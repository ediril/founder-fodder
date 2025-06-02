#!/usr/bin/env python3
import sqlite3
import requests
from bs4 import BeautifulSoup
import os
import time
import json
from urllib.parse import urlparse
import argparse
import html

OLLAMA_MODEL = "gemma3:1b" # "gemma2:2b" "gemma3:4b" "qwen2.5:3b"
OLLAMA_TIMEOUT = 180

# Prompts for Ollama
STORY_PROMPT = "Create summary of the following page for main topics and insights. Try to limit summary to 200 words. Don't restate the question, return just summary:\n------\n{content}"
COMMENTS_PROMPT = "Create summary of the following discussion for main arguments, opinions, criticisms. Try to limit summary to 600 words. Don't restate the question, return just summary:\n------\n{content}"

STORY_SUMMARY_FILE = "story-summary.md"
COMMENTS_SUMMARY_FILE = "comments-summary.md"

class StorySummarizer:
    """Downloads story content and comments from Hacker News, summarizes them with Ollama LLM"""
    
    # API Endpoints
    BASE_URL = "https://hacker-news.firebaseio.com/v0"
    ITEM_URL = f"{BASE_URL}/item"
    
    def __init__(self, db_path="stories.db", output_dir="static/content", originals_dir="static/_originals"):
        """Initialize with database path and output directory"""
        self.db_path = db_path
        self.output_dir = output_dir
        self.originals_dir = originals_dir
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        })

        os.makedirs(self.originals_dir, exist_ok=True)
        os.makedirs(self.output_dir, exist_ok=True)
    
    def decode_html_entities(self, text):
        """Decode HTML entities in text to their corresponding characters"""
        if not text:
            return text
        return html.unescape(text)
    
    def fetch_story_content(self, url):
        """Fetch and extract main content from a story URL"""
        if not url or url.startswith("item?id="):
            return "No URL provided or URL is a HN discussion."
        
        try:
            response = self.session.get(url, timeout=10)
            if not response.ok:
                return f"Failed to fetch content: HTTP {response.status_code}"
            
            # Parse the content
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Remove script and style elements
            for script in soup(["script", "style"]):
                script.decompose()
            
            # Try to find the main content
            main_content = ""
            
            # Try to find article tag or main content div
            article = soup.find('article') or soup.find('main') or soup.find(id='content') or soup.find(class_='content')
            if article:
                main_content = article.get_text(separator='\n', strip=True)
            else:
                if soup.body:
                    main_content = soup.body.get_text(separator='\n', strip=True)
            
            # Clean up the content (remove excessive newlines, etc.)
            main_content = '\n'.join(line.strip() for line in main_content.splitlines() if line.strip())
            
            # Decode HTML entities
            main_content = self.decode_html_entities(main_content)

            if not main_content:
                print(" .. could not find any story content")
            
            return main_content
        except Exception as e:
            return f"Error fetching content: {str(e)}"
    
    def fetch_comment(self, comment_id):
        """Fetch a single comment by ID from the Hacker News API"""
        try:
            response = self.session.get(f"{self.ITEM_URL}/{comment_id}.json", timeout=10)
            if not response.ok:
                return None
            
            comment = response.json()
            if not comment or comment.get('dead') or comment.get('deleted'):
                return None
            
            # Decode HTML entities in the comment text
            if 'text' in comment:
                comment['text'] = self.decode_html_entities(comment['text'])
            
            return comment
        except Exception as e:
            print(f"Error fetching comment {comment_id}: {str(e)}")
            return None
    
    def fetch_comments_recursive(self, comment_ids, depth=0, max_depth=5):
        """Recursively fetch comments and their replies"""
        if not comment_ids or depth > max_depth:
            return []
        
        comments = []
        for comment_id in comment_ids:
            comment = self.fetch_comment(comment_id)
            if not comment:
                continue
            
            # Extract the comment text
            comment_text = comment.get('text', '')
            if not comment_text:
                continue
            
            # Create a comment object with text and replies
            comment_obj = {
                'id': comment_id,
                'text': comment_text,
                'by': comment.get('by', ''),
                'time': comment.get('time', 0),
                'replies': []
            }
            
            # Recursively fetch replies if any
            if 'kids' in comment and comment['kids']:
                replies = self.fetch_comments_recursive(comment['kids'], depth + 1, max_depth)
                comment_obj['replies'] = replies
            
            comments.append(comment_obj)
            
            # Small delay to be nice to the API
            time.sleep(0.1)
        
        return comments
    
    def fetch_comments(self, comments_url):
        """Fetch and extract comments from a HN discussion URL using the API"""
        # Extract the story ID from the comments URL
        # Format is typically: https://news.ycombinator.com/item?id=123456
        parsed_url = urlparse(comments_url)
        query_params = dict(param.split('=') for param in parsed_url.query.split('&') if '=' in param)
        story_id = query_params.get('id')
        
        if not story_id:
            return "Could not extract story ID from comments URL."
        
        # Fetch the story details to get the top-level comment IDs
        response = self.session.get(f"{self.ITEM_URL}/{story_id}.json", timeout=10)
        if not response.ok:
            return f"Failed to fetch story details: HTTP {response.status_code}"
        
        story = response.json()
        if not story or 'kids' not in story:
            return ""
        
        # Fetch all comments recursively
        print(f"  Fetching comments for story {story_id}...")
        comments = self.fetch_comments_recursive(story['kids'])
        
        # Format comments for summarization
        combined_comments = ""
        for i, comment in enumerate(comments):
            combined_comments += self.format_comment(comment, i+1)
        
        if not combined_comments:
            return ""
        
        # Ensure all HTML entities are decoded
        combined_comments = self.decode_html_entities(combined_comments)
        
        return combined_comments
    
    def cleanup_crap(self, body):
        """Remove lines that begin with 'Comment' (with possible leading whitespace)"""
        if not body:
            return body
            
        # Split the text into lines
        lines = body.split('\n')
        
        cleaned_lines = []
        for line in lines:
            lline = line.strip().lower()

            # These tend to be at the beginning
            if lline.startswith('summary ') or lline.startswith("here's ") \
                or lline.startswith('here’s ') or lline.startswith('here is '):
                continue
            if lline.startswith('okay, '):
                continue

            # These seem to arrive at the end
            if "you like me to" in lline or "you want me to" in lline:
                break
            if lline == "---" or lline.startswith("let me know if "):
                break
            
            cleaned_lines.append(line)
        
        # Join the lines back together
        return '\n'.join(cleaned_lines)
    
    def format_comment(self, comment, index, depth=0):
        """Format a comment and its replies for summarization"""
        indent = "  " * depth
        result = f"{indent}Comment {index}:\n{indent}{comment['text']}\n\n"
        
        # Add replies
        for i, reply in enumerate(comment.get('replies', [])):
            result += self.format_comment(reply, f"{index}.{i+1}", depth + 1)
        
        return result
    
    def summarize(self, content, prompt_type):
        """Use Ollama to summarize content"""
        try:
            # Define prompts based on content type
            if prompt_type == "story":
                prompt = STORY_PROMPT.format(content=content)
            else:  # comments
                prompt = COMMENTS_PROMPT.format(content=content)
            
            # Make request to Ollama API
            response = requests.post(
                "http://localhost:11434/api/generate",
                json={
                    "model": OLLAMA_MODEL,
                    "prompt": prompt,
                    "stream": False
                },
                timeout=OLLAMA_TIMEOUT
            )
            
            if response.status_code == 200:
                result = response.json()
                return result.get("response", None)

            error_msg = f"ERROR: Failed to get summary: HTTP {response.status_code}"
        except requests.exceptions.ConnectionError:
            error_msg = "ERROR: Could not connect to Ollama. Is it running?"
        except requests.exceptions.Timeout:
            error_msg = f"ERROR: Ollama request timed out after {OLLAMA_TIMEOUT} seconds"
        except json.JSONDecodeError:
            error_msg = "ERROR: Invalid JSON response from Ollama"
        except Exception as e:
            error_msg = f"ERROR: Unexpected error during summarization: {str(e)}"

        print(error_msg)
        return None
    
    def process_story(self, story, download_only=False):
        """Process a single story: download content, summarize if needed"""
        print(f"Processing story {story['id']}")
        
        # Create output directory if it doesn't exist
        os.makedirs(self.output_dir, exist_ok=True)
        
        # Download story content
        story_content = ""
        story_file = os.path.join(self.originals_dir, f"{story['id']}-story.txt")
        if story['url'] and not os.path.exists(story_file):
            print("Fetching story...")
            story_content = self.fetch_story_content(story['url'])            
            # Save original content
            if story_content:
                with open(story_file, 'w', encoding='utf-8') as f:
                    f.write(story_content)
                print("Saved story...")
        
        # Download comments
        comments_content = ""
        comments_file = os.path.join(self.originals_dir, f"{story['id']}-comments.txt")
        if story['comments_url'] and not os.path.exists(comments_file):
            print("Fetching comments...")
            comments_content = self.fetch_comments(story['comments_url'])
            if comments_content:
                with open(comments_file, 'w', encoding='utf-8') as f:
                    f.write(comments_content)
                print("Comments saved...")

        if download_only:
            print(f"  Download-only mode: skipping summarization for story {story['id']}")
            return
        
        # Summarize story content if we have it
        if not story_content and os.path.exists(story_file):
            with open(story_file, 'r', encoding='utf-8') as f:
                story_content = f.read()
            print("Loaded existing story content...")

        summary_file = os.path.join(self.output_dir, f"{story['id']}-{STORY_SUMMARY_FILE}")
        if story_content and not os.path.exists(summary_file):
            print("  Summarizing story content...")
            start_time = time.time()
            if story_summary := self.summarize(story_content, "story"):
                story_summary = self.cleanup_crap(story_summary)

                # Save the story summary
                with open(summary_file, 'w', encoding='utf-8') as f:
                    f.write(story_summary)
                print(f"  Saved story summary to {summary_file}")
                
                # Calculate and display elapsed time
                elapsed_time = time.time() - start_time
                print(f"  Story {story['id']} summarized in {elapsed_time:.2f} sec")
        
        # Generate comments summary if needed and we have comments
        if not comments_content and os.path.exists(comments_file):
            # Load existing comments content
            with open(comments_file, 'r', encoding='utf-8') as f:
                comments_content = f.read()
            print("Loaded existing comments content...")
        
        if comments_content and len(comments_content) > 1000:
            comments_summary_file = os.path.join(self.output_dir, f"{story['id']}-{COMMENTS_SUMMARY_FILE}")
            if not os.path.exists(comments_summary_file):
                print("  Summarizing comments...")
                start_time = time.time()
                if comments_summary := self.summarize(comments_content, "comments"):
                    comments_summary = self.cleanup_crap(comments_summary)

                    # Save the comments summary
                    with open(comments_summary_file, 'w', encoding='utf-8') as f:
                        f.write(comments_summary)
                    print(f"  Saved comments summary to {comments_summary_file}")
                    
                    # Calculate and display elapsed time
                    elapsed_time = time.time() - start_time
                    print(f"  Comments {story['id']} summarized in {elapsed_time:.2f} sec")

        # Sleep to be nice to APIs and servers
        time.sleep(1)
    
    def run(self, story_type="top", limit=10, download_only=False):
        """Run the summarization process"""
        print(f"Looking for {story_type} stories to process (limit: {limit})...")
        with sqlite3.connect(self.db_path) as conn:
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            
            cursor.execute('''
                SELECT id, title, url, domain, comments_url
                FROM stories
                WHERE type = ?
                ORDER BY ranking
            ''', (story_type,))
            
            story_cnt = 0
            for story in cursor.fetchall():
                self.process_story(story, download_only)

                story_cnt += 1
                if limit > 0 and story_cnt == limit:
                    break

            print(f"Completed processing of {story_cnt} stories.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Summarize Hacker News stories and comments')
    parser.add_argument('--type', choices=['top', 'new', 'best'], default='top',
                        help='Type of stories to summarize (default: top)')
    parser.add_argument('--limit', type=int, default=-1,
                        help='Maximum number of stories to process (default: _all_)')
    parser.add_argument('--db', default='stories.db',
                        help='Path to SQLite database (default: stories.db)')
    parser.add_argument('--output', default='static/content',
                        help='Output directory for content files (default: static/content)')
    parser.add_argument('--download-only', action='store_true',
                        help='Download story and comments content without summarizing')
    parser.add_argument('--story-id', type=int,
                        help='Process a single story by ID')
    
    args = parser.parse_args()

    summarizer = StorySummarizer(db_path=args.db, output_dir=args.output)
    
    if args.story_id:
        # Process a single story by ID
        with sqlite3.connect(args.db) as conn:
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            cursor.execute('''
                SELECT id, title, url, domain, comments_url
                FROM stories
                WHERE id = ?
            ''', (args.story_id,))
            story = cursor.fetchone()
            
            if story:
                summarizer.process_story(story, args.download_only)
            else:
                print(f"Story ID {args.story_id} not found in database.")
    else:
        summarizer.run(story_type=args.type, limit=args.limit, download_only=args.download_only)