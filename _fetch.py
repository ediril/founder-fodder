import requests
import sqlite3
import time
import os
from urllib.parse import urlparse
import argparse
from bs4 import BeautifulSoup

class HackerNewsFetcher:
    """Fetches stories from Hacker News API and stores them in SQLite"""
    
    # API Endpoints
    BASE_URL = "https://hacker-news.firebaseio.com/v0"
    ENDPOINTS = {
        "top": f"{BASE_URL}/topstories.json",
        "new": f"{BASE_URL}/newstories.json",
        "best": f"{BASE_URL}/beststories.json"
    }
    ITEM_URL = f"{BASE_URL}/item"
    
    def __init__(self, db_path="hn_stories.db", img_dir="static/img/stories"):
        """Initialize with database path and image directory"""
        self.db_path = db_path
        self.img_dir = img_dir
        self.init_db()
        self.session = requests.Session()
        
        # Ensure image directory exists
        os.makedirs(self.img_dir, exist_ok=True)
    
    def init_db(self):
        """Initialize the database if it doesn't exist"""
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            
            # Create the stories table if it doesn't exist
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS stories (
                    id INTEGER PRIMARY KEY,
                    title TEXT,
                    url TEXT,
                    domain TEXT,
                    comments_url TEXT,
                    comments_count INTEGER,
                    score INTEGER,
                    user TEXT,
                    timestamp INTEGER,
                    fetched_at INTEGER,
                    type TEXT,
                    ranking INTEGER
                )
            ''')
            
            # Create indices for faster queries
            cursor.execute('CREATE INDEX IF NOT EXISTS idx_story_type ON stories(type, ranking)')
            cursor.execute('CREATE INDEX IF NOT EXISTS idx_story_timestamp ON stories(timestamp)')
            
            conn.commit()
    
    def fetch_story_ids(self, story_type):
        """Fetch story IDs from the specified endpoint"""
        response = self.session.get(self.ENDPOINTS.get(story_type))
        if not response.ok:
            print(f"Error fetching {story_type} stories: {response.status_code}")
            return []
        
        return response.json()
    
    def fetch_story_details(self, story_id):
        """Fetch details for a single story by ID"""
        response = self.session.get(f"{self.ITEM_URL}/{story_id}.json")
        if not response.ok:
            print(f"Error fetching story {story_id}: {response.status_code}")
            return None
        
        return response.json()
        
    def save_story_image(self, story_id, image_url):
        """Save image for a story from the web"""
        # Check if image already exists with any extension
        for ext in ['.jpg', '.png', '.gif', '.webp']:
            filepath = os.path.join(self.img_dir, f"{story_id}{ext}")
            if os.path.exists(filepath):
                # Silently skip if image already exists
                return
        
        # If no URL or invalid URL, don't save anything
        if not image_url or not image_url.startswith(('http://', 'https://')):
            print(f"No valid image URL for story {story_id}")
            return
        
        try:
            # Download the image
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            }
            
            # First check if the URL is actually an image
            head_response = self.session.head(image_url, headers=headers, timeout=10, allow_redirects=True)
            content_type = head_response.headers.get('Content-Type', '')
            
            # If it's not an image, try to extract an image from the page
            if not content_type.startswith('image/'):                
                # Try to extract an image from the page
                try:
                    response = self.session.get(image_url, headers=headers, timeout=10)
                    if response.ok:
                        soup = BeautifulSoup(response.text, 'html.parser')
                        
                        # Look for images in the page
                        images = soup.find_all('img')
                        for img in images:
                            src = img.get('src')
                            if not src:
                                continue
                                
                            # Make relative URLs absolute
                            if not src.startswith(('http://', 'https://')):
                                parsed_url = urlparse(image_url)
                                base_url = f"{parsed_url.scheme}://{parsed_url.netloc}"
                                if src.startswith('/'):
                                    src = f"{base_url}{src}"
                                else:
                                    src = f"{base_url}/{src.lstrip('/')}"
                            
                            # Check if this URL is an image
                            try:
                                img_head = self.session.head(src, headers=headers, timeout=5, allow_redirects=True)
                                if img_head.ok and img_head.headers.get('Content-Type', '').startswith('image/'):
                                    image_url = src
                                    break
                            except:
                                continue
                except Exception as e:
                    print(f"Error extracting image from page for story {story_id}: {str(e)}")
            
            # Now try to download the image
            response = self.session.get(image_url, headers=headers, timeout=10, stream=True)
            
            if response.ok:
                # Check if it's actually an image
                content_type = response.headers.get('Content-Type', '')
                if content_type.startswith('image/'):
                    # Determine file extension based on content type
                    ext = '.jpg'  # default
                    if 'jpeg' in content_type or 'jpg' in content_type:
                        ext = '.jpg'
                    elif 'png' in content_type:
                        ext = '.png'
                    elif 'gif' in content_type:
                        ext = '.gif'
                    elif 'webp' in content_type:
                        ext = '.webp'
                    
                    # Update filepath with correct extension
                    filepath = os.path.join(self.img_dir, f"{story_id}{ext}")
                    
                    # Save the image
                    with open(filepath, 'wb') as f:
                        for chunk in response.iter_content(chunk_size=8192):
                            f.write(chunk)
                    print(f"Successfully downloaded image for story {story_id}")
                    return
                else:
                    pass
            
            print(f"Failed to download image for story {story_id}: HTTP {response.status_code}")
        except requests.exceptions.Timeout:
            print(f"Timeout downloading image for story {story_id}")
        except requests.exceptions.ConnectionError:
            print(f"Connection error downloading image for story {story_id}")
        except Exception as e:
            print(f"Error downloading image for story {story_id}: {str(e)}")
            
    def extract_image_url(self, url):
        """Extract main image URL from a web page"""
        if not url:
            return None
            
        try:
            # Check if URL points to a file type we should skip
            parsed_url = urlparse(url)
            path = parsed_url.path.lower()
            
            # Skip common non-HTML file types
            skip_extensions = [
                '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
                '.txt', '.rtf', '.csv', '.zip', '.rar', '.tar', '.gz',
                '.mp3', '.mp4', '.avi', '.mov', '.wmv', '.flv', '.mkv',
                '.exe', '.dmg', '.iso', '.img', '.bin', '.dat'
            ]
            
            if any(path.endswith(ext) for ext in skip_extensions):
                print(f"Skipping image extraction for non-HTML file: {url}")
                return None
                
            headers = {
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
            }
            
            # Follow redirects and get the final URL
            response = self.session.get(url, headers=headers, timeout=10, allow_redirects=True)
            final_url = response.url
            
            # Check content type to skip non-HTML responses
            content_type = response.headers.get('Content-Type', '').lower()
            if not content_type.startswith('text/html') and 'application/json' not in content_type:
                print(f"Skipping image extraction for non-HTML content type: {content_type} - {url}")
                return None
                
            if not response.ok:
                print(f"Failed to fetch page {url}: {response.status_code}")
                return None
                
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Parse the base URL for relative path resolution
            parsed_url = urlparse(final_url)
            base_url = f"{parsed_url.scheme}://{parsed_url.netloc}"
            
            # Get the directory part of the URL
            path_parts = parsed_url.path.split('/')
            if '.' in path_parts[-1]:  # If last part looks like a file
                directory = '/'.join(path_parts[:-1])
            else:
                directory = parsed_url.path
            if not directory.endswith('/'):
                directory += '/'
            
            # Try to find the main image using common patterns
            # 1. Open Graph image
            og_image = soup.find('meta', property='og:image')
            if og_image and og_image.get('content'):
                img_url = og_image['content']
                if not img_url.startswith(('http://', 'https://')):
                    img_url = f"{base_url}/{img_url.lstrip('/')}"
                return img_url
                
            # 2. Twitter card image
            twitter_image = soup.find('meta', {'name': 'twitter:image'})
            if twitter_image and twitter_image.get('content'):
                img_url = twitter_image['content']
                if not img_url.startswith(('http://', 'https://')):
                    img_url = f"{base_url}/{img_url.lstrip('/')}"
                return img_url
                
            # 3. Look for large images in content
            images = soup.find_all('img')
            large_images = []
            medium_images = []
            
            for img in images:
                src = img.get('src')
                if not src:
                    continue
                
                # Skip icons, logos, avatars, etc.
                if any(term in src.lower() for term in ['icon', 'logo', 'avatar', 'favicon', 'spinner', 'button']):
                    continue
                
                # Make relative URLs absolute
                if src.startswith('/'):
                    src = f"{base_url}{src}"
                elif not src.startswith(('http://', 'https://')):
                    src = f"{base_url}/{src.lstrip('/')}"
                
                # Check for data-src or other lazy loading attributes
                for attr in ['data-src', 'data-original', 'data-lazy-src', 'data-srcset', 'data-url']:
                    lazy_src = img.get(attr)
                    if lazy_src:
                        if not lazy_src.startswith(('http://', 'https://')):
                            lazy_src = f"{base_url}/{lazy_src.lstrip('/')}"
                        large_images.append(lazy_src)
                        break
                
                # Check dimensions if available
                width = img.get('width')
                height = img.get('height')
                
                if width and height:
                    try:
                        w, h = int(width), int(height)
                        if w >= 300 and h >= 300:
                            large_images.append(src)
                        elif w >= 200 and h >= 200:
                            medium_images.append(src)
                    except ValueError:
                        pass
                
                # If no dimensions but has alt text, might be a content image
                if img.get('alt') and len(img.get('alt', '')) > 10:
                    medium_images.append(src)
            
            # Return the first large image if available
            if large_images:
                return large_images[0]
            
            # Otherwise return the first medium image
            if medium_images:
                return medium_images[0]
            
            # If still no images, try to find any image that's not obviously an icon
            for img in images:
                src = img.get('src')
                if src and not any(term in src.lower() for term in ['icon', 'logo', 'avatar', 'favicon']):
                    if not src.startswith(('http://', 'https://')):
                        src = f"{base_url}/{src.lstrip('/')}"
                    return src
            
            # Try to find images in common related paths
            common_img_paths = [
                'images',
                'img',
                'assets',
                'static',
                'media',
                'resources',
                'figures',
                'pics'
            ]
            
            # Check for images in related paths
            for img_path in common_img_paths:
                img_url = f"{base_url}/{img_path}/"
                try:
                    img_response = self.session.head(img_url, headers=headers, timeout=5)
                    if img_response.ok:
                        # If directory exists, look for common image names
                        for img_name in ['cover', 'main', 'header', 'preview', 'thumbnail']:
                            for ext in ['.jpg', '.jpeg', '.png', '.gif']:
                                test_url = f"{img_url}{img_name}{ext}"
                                try:
                                    test_response = self.session.head(test_url, headers=headers, timeout=5)
                                    if test_response.ok:
                                        return test_url
                                except:
                                    continue
                except:
                    continue
                
            return None
        except Exception as e:
            print(f"Error extracting image from {url}: {str(e)}")
            return None
    
    def extract_domain(self, url):
        """Extract domain from URL"""
        if not url:
            return ""
        
        try:
            parsed_url = urlparse(url)
            return parsed_url.netloc
        except:
            return ""
    
    def store_story(self, story, story_type, ranking):
        """Store a story in the database"""
        if not story:
            return
        
        # Extract fields with defaults for missing values
        story_id = story.get('id')
        title = story.get('title', '')
        url = story.get('url', '')
        domain = self.extract_domain(url)
        comments_url = f"https://news.ycombinator.com/item?id={story_id}"
        comments_count = story.get('descendants', 0)
        score = story.get('score', 0)
        user = story.get('by', '')
        timestamp = story.get('time', 0)
        fetched_at = int(time.time())
        
        # Check if story already exists in the database
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            cursor.execute('SELECT id FROM stories WHERE id = ?', (story_id,))
            existing = cursor.fetchone()
        
        # Check if image already exists for this story
        image_exists = False
        for ext in ['.jpg', '.png', '.gif', '.webp']:
            if os.path.exists(os.path.join(self.img_dir, f"{story_id}{ext}")):
                image_exists = True
                break
        
        # Only try to fetch image if it doesn't exist
        if not image_exists and url:
            try:
                # Try to extract image URL from the story's page
                image_url = self.extract_image_url(url)
                self.save_story_image(story_id, image_url)
            except Exception as e:
                print(f"Error processing image for story {story_id}: {str(e)}")
                self.save_story_image(story_id, None)
        elif not image_exists:
            # If no URL, use default image
            self.save_story_image(story_id, None)
            
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            
            # Check if story already exists
            cursor.execute('SELECT id FROM stories WHERE id = ?', (story_id,))
            existing = cursor.fetchone()
            
            if existing:
                # Update existing story
                cursor.execute('''
                    UPDATE stories
                    SET title = ?, url = ?, domain = ?, comments_url = ?,
                        comments_count = ?, score = ?, user = ?,
                        timestamp = ?, fetched_at = ?, type = ?, ranking = ?
                    WHERE id = ?
                ''', (title, url, domain, comments_url, comments_count, score, 
                      user, timestamp, fetched_at, story_type, ranking, story_id))
            else:
                # Insert new story
                cursor.execute('''
                    INSERT INTO stories
                    (id, title, url, domain, comments_url, comments_count, 
                     score, user, timestamp, fetched_at, type, ranking)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ''', (story_id, title, url, domain, comments_url, comments_count,
                      score, user, timestamp, fetched_at, story_type, ranking))
            
            conn.commit()
    
    def fetch_and_store(self, story_type="top", limit=100):
        """Fetch stories of the specified type and store them"""
        print(f"\nFetching {story_type} stories...")
        story_ids = self.fetch_story_ids(story_type)
        
        if not story_ids:
            print(f"No {story_type} story IDs found")
            return 0
        
        # Limit the number of stories to process
        story_ids = story_ids[:limit]
        
        # Get the count of stories we're going to fetch
        # This will be used to shift down existing stories
        new_stories_count = len(story_ids)
        
        # Shift down the rankings of existing stories that aren't being refetched
        with sqlite3.connect(self.db_path) as conn:
            cursor = conn.cursor()
            
            # Get the current max ranking for this story type
            cursor.execute('SELECT MAX(ranking) FROM stories WHERE type = ?', (story_type,))
            max_ranking = cursor.fetchone()[0] or 0
            
            # Update rankings for existing stories that aren't in the new list
            # by shifting them down by the number of new stories
            cursor.execute('''
                UPDATE stories 
                SET ranking = ranking + ?
                WHERE type = ? AND id NOT IN ({})
            '''.format(','.join(['?'] * len(story_ids))), 
            [new_stories_count, story_type] + story_ids)
            
            conn.commit()
        
        count = 0
        for ranking, story_id in enumerate(story_ids):
            story = self.fetch_story_details(story_id)
            if story:
                self.store_story(story, story_type, ranking)
                count += 1
                
                # Print progress every 10 stories
                if count % 10 == 0:
                    print(f"Processed {count}/{len(story_ids)} {story_type} stories")
                
                # Small delay to be nice to the API
                time.sleep(0.1)
        
        print(f"Completed fetching {count} {story_type} stories")
        return count
    
    def fetch_all(self, limit=100):
        """Fetch all types of stories"""
        total = 0
        for story_type in self.ENDPOINTS.keys():
            count = self.fetch_and_store(story_type, limit)
            total += count
        
        print(f"Total stories fetched: {total}")
        return total


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Fetch stories from Hacker News')
    parser.add_argument('--type', choices=['top', 'new', 'best', 'all'], default='all',
                        help='Type of stories to fetch (default: all)')
    parser.add_argument('--limit', type=int, default=100,
                        help='Maximum number of stories to fetch per type (default: 100)')
    parser.add_argument('--db', default='hn_stories.db',
                        help='Path to SQLite database (default: hn_stories.db)')
    
    args = parser.parse_args()
    
    fetcher = HackerNewsFetcher(db_path=args.db)
    
    if args.type == 'all':
        fetcher.fetch_all(limit=args.limit)
    else:
        fetcher.fetch_and_store(story_type=args.type, limit=args.limit)

