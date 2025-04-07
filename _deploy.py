#!/usr/bin/env python3
"""
Deploy script to push assets, database, or code to web host via FTP.
Reads credentials from a YAML config file.
"""
import os
import argparse
import ftplib
import yaml
import sqlite3
from datetime import datetime

def load_config(config_file):
    """
    Load FTP configuration from YAML file.
    
    Args:
        config_file: Path to the YAML configuration file
        
    Returns:
        Dictionary containing FTP configuration
    """
    try:
        with open(config_file, 'r') as f:
            config = yaml.safe_load(f)
        
        # Validate required fields
        required_fields = ['host', 'user', 'password']
        for field in required_fields:
            if field not in config:
                raise ValueError(f"Missing required field '{field}' in config file")
        
        # Set default for optional fields
        if 'remote_dir' not in config:
            config['remote_dir'] = ''
            
        return config
    except Exception as e:
        print(f"Error loading config file: {e}")
        exit(1)

def deploy_content(ftp, remote_dir):
    """
    Deploy content (images and database) to the web host.
    
    Args:
        ftp: FTP connection
        remote_dir: Remote directory path
    """
    # Clean up unused images before deployment
    cleanup_unused_images()
    
    # Navigate to remote directory
    if remote_dir:
        try:
            ftp.cwd(remote_dir)
        except ftplib.error_perm:
            # Create directory if it doesn't exist
            print(f"Creating directory {remote_dir}")
            ftp.mkd(remote_dir)
            ftp.cwd(remote_dir)
    
    print("Uploading story images...")
    image_dir = os.path.join('static', 'img', 'stories')
    upload_directory(ftp, image_dir, 'static/img/stories', remote_dir)
    
    print("Uploading summaries...")
    content_dir = os.path.join('static', 'content')
    upload_directory(ftp, content_dir, 'static/content', remote_dir)
    
    print("Uploading database...")
    ftp.cwd(f"/{remote_dir}")  # Go to root first
    with open('hn_stories.db', 'rb') as f:
        ftp.storbinary('STOR hn_stories.db', f)

def deploy_code(ftp, remote_dir):
    """
    Deploy code (PHP, JS, CSS) to the web host.
    
    Args:
        ftp: FTP connection
        remote_dir: Remote directory path
    """
    # Navigate to remote directory
    if remote_dir:
        try:
            ftp.cwd(remote_dir)
        except ftplib.error_perm:
            # Create directory if it doesn't exist
            print(f"Creating directory {remote_dir}")
            ftp.mkd(remote_dir)
            ftp.cwd(remote_dir)
    
    # Upload PHP files
    print("Uploading PHP files...")
    php_files = ['index.php']
    for file in php_files:
        with open(file, 'rb') as f:
            ftp.storbinary(f'STOR {file}', f)
            print(f"Uploaded {file}")
    # Upload template files
    upload_directory(ftp, 'templates', 'templates', remote_dir)
    upload_directory(ftp, 'vendor', 'vendor', remote_dir)

    # Upload CSS files
    print("Uploading CSS files...")
    css_dir = os.path.join('static', 'css')
    upload_directory(ftp, css_dir, 'static/css', remote_dir)

    # Upload JS files
    print("Uploading JS files...")
    js_dir = os.path.join('static', 'js')
    upload_directory(ftp, js_dir, 'static/js', remote_dir)


def upload_directory(ftp, local_dir, upload_dir, base_dir):
    """
    Upload a directory and its contents recursively.
    
    Args:
        ftp: FTP connection
        local_dir: Local directory path
        remote_dir: Remote directory path
        base_remote_dir: Base remote directory path (if any)
    """
    # Make sure the local directory exists
    if not os.path.exists(local_dir):
        print(f"Warning: Local directory {local_dir} does not exist, skipping...")
        return
    
    # First, go to the remote base directory
    ftp.cwd(base_dir)
    
    # Create remote directory structure recursively if missing
    remote_parts = upload_dir.split('/')
    for part in remote_parts:
        if not part:
            continue
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            # Try to create the directory
            ftp.mkd(part)
            ftp.cwd(part)                

    # Get list of files in the remote directory
    remote_files = []
    try:
        remote_files = set(ftp.nlst())
    except ftplib.error_perm as e:
        print(f"Warning: Could not list remote directory contents: {e}")
    
    # Track local files for deletion check
    local_files = set()
    
    # Upload all files in the directory
    for item in os.listdir(local_dir):
        # Skip .DS_Store files
        if item == '.DS_Store':
            continue
            
        local_path = os.path.join(local_dir, item)
        local_files.add(item)
        
        if os.path.isfile(local_path):
            # Check if file exists on remote server
            file_exists = item in remote_files
            
            # For code files, always upload to ensure changes are applied
            # For content files (images), skip if they exist (not expected to change)
            if file_exists and 'static/img' in local_dir:
                continue
            
            # Upload the file
            try:
                with open(local_path, 'rb') as f:
                    ftp.storbinary(f'STOR {item}', f)
                    print(f"Uploaded {local_path}")
            except ftplib.error_perm as e:
                print(f"Error uploading {local_path}: {e}")
        elif os.path.isdir(local_path) and not item.startswith('__'):
            # Handle subdirectories, skip __pycache__ and similar
            upload_directory(ftp, local_path, item, '')
    
    # Delete remote files that don't exist locally
    if 'static/img' or 'static/content' in local_dir:
        files_to_delete = remote_files - local_files
        for file_to_delete in files_to_delete:
            # Skip special directory entries
            if file_to_delete in ['.', '..']:
                continue
            try:
                ftp.delete(file_to_delete)
                print(f"Deleted remote file: {file_to_delete}")
            except ftplib.error_perm as e:
                print(f"Error deleting remote file {file_to_delete}: {e}")
    
    # Go back up a directory
    ftp.cwd('..')

def cleanup_unused_images():
    """
    Remove images from static/img/stories that don't exist in the database.
    """
    print("Cleaning up unused images...")
    
    # Connect to the database
    try:
        conn = sqlite3.connect('hn_stories.db')
        cursor = conn.cursor()
        
        # Get all story IDs from the database
        cursor.execute("SELECT id FROM stories")
        story_ids = [str(row[0]) for row in cursor.fetchall()]
        
        # Close the database connection
        conn.close()
        
        # Get all image files in the static/img/stories directory
        image_dir = os.path.join('static', 'img', 'stories')
        if not os.path.exists(image_dir):
            print(f"Warning: Image directory {image_dir} does not exist, skipping cleanup.")
            return
            
        image_files = [f for f in os.listdir(image_dir) if f.endswith('.jpg') or f.endswith('.png')]
        
        # Find images that don't have corresponding stories in the database
        unused_images = []
        for image_file in image_files:
            # Extract story ID from image filename (assuming format: story_id.jpg or story_id.png)
            story_id = image_file.split('.')[0]
            if story_id not in story_ids:
                unused_images.append(image_file)
        
        # Remove unused images
        for image_file in unused_images:
            image_path = os.path.join(image_dir, image_file)
            try:
                os.remove(image_path)
                print(f"Removed unused image: {image_file}")
            except Exception as e:
                print(f"Error removing image {image_file}: {e}")
        
        print(f"Cleanup complete. Removed {len(unused_images)} unused images.")
        
    except Exception as e:
        print(f"Error during image cleanup: {e}")

def main():
    parser = argparse.ArgumentParser(description='Deploy to web host via FTP')
    parser.add_argument('--mode', choices=['content', 'code', 'all'], default='content',
                        help='Deployment mode: content (images/db), code (PHP/JS/CSS), or all (default: content)')
    parser.add_argument('--config', default='ftp_config.yaml', 
                        help='Path to YAML configuration file (default: ftp_config.yaml)')
    args = parser.parse_args()
    
    # Load config from file
    config = load_config(args.config)
    host = config['host']
    user = config['user']
    password = config['password']
    remote_dir = config['remote_dir']
    
    print(f"Starting deployment at {datetime.now().strftime('%Y-%m-%d %H:%M:%S')}")
    print(f"Mode: {args.mode}")
    print(f"Deploying to {host} as {user}")
    print(f"Using explicit FTP over TLS")
    
    # Connect to FTP server using explicit TLS
    try:
        # Use explicit FTPS (FTP over TLS)
        ftp = ftplib.FTP_TLS(host)
        ftp.login(user, password)
        ftp.prot_p()  # Set up secure data connection
        print(f"Connected to {host} using TLS")
        
        # Deploy based on selected mode
        if args.mode == 'code' or args.mode == 'all':
            deploy_code(ftp, remote_dir)

        if args.mode == 'content' or args.mode == 'all':
            deploy_content(ftp, remote_dir)
        
        print("Deployment completed successfully!")
        ftp.quit()
        
    except ftplib.all_errors as e:
        print(f"FTP error: {e}")
        exit(1)

if __name__ == '__main__':
    main()