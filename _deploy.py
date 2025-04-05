#!/usr/bin/env python3
"""
Deploy script to push assets, database, or code to web host via FTP.
Reads credentials from a YAML config file.
"""
import os
import argparse
import ftplib
import yaml
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
    # Navigate to remote directory
    if remote_dir:
        try:
            ftp.cwd(remote_dir)
        except ftplib.error_perm:
            # Create directory if it doesn't exist
            print(f"Creating directory {remote_dir}")
            ftp.mkd(remote_dir)
            ftp.cwd(remote_dir)
    
    # Upload images
    print("Uploading images...")
    image_dir = os.path.join('static', 'img', 'stories')
    upload_directory(ftp, image_dir, 'static/img/stories')
    
    # Upload database
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
    
    # Upload CSS files
    print("Uploading CSS files...")
    css_dir = os.path.join('static', 'css')
    upload_directory(ftp, css_dir, 'static/css', remote_dir)

    # Upload JS files
    print("Uploading JS files...")
    js_dir = os.path.join('static', 'js')
    upload_directory(ftp, js_dir, 'static/js', remote_dir)

    # Upload template files
    print("Uploading template files...")
    template_dir = 'templates'
    upload_directory(ftp, template_dir, 'templates', remote_dir)

def upload_directory(ftp, local_dir, remote_dir, base_remote_dir=''):
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
    
    # First, go to the base remote directory if specified
    if base_remote_dir:
        try:
            ftp.cwd('/')  # Go to root first
            if base_remote_dir:
                ftp.cwd(base_remote_dir)
        except ftplib.error_perm as e:
            print(f"Error navigating to base directory {base_remote_dir}: {e}")
            return
    
    # Create remote directory structure recursively
    remote_parts = remote_dir.split('/')
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
    
    # Delete remote files that don't exist locally (only for image directories)
    if 'static/img' in local_dir:
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
        if args.mode == 'content' or args.mode == 'all':
            deploy_content(ftp, remote_dir)
            
        if args.mode == 'code' or args.mode == 'all':
            deploy_code(ftp, remote_dir)
        
        print("Deployment completed successfully!")
        ftp.quit()
        
    except ftplib.all_errors as e:
        print(f"FTP error: {e}")
        exit(1)

if __name__ == '__main__':
    main()