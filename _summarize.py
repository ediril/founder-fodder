#!/usr/bin/env python3
import sqlite3
import requests
from bs4 import BeautifulSoup
import os
import time
import json
from urllib.parse import urlparse
import argparse

class StorySummarizer:
    """Downloads story content and comments from Hacker News, summarizes them with Ollama LLM"""
    
    def __init__(self, db_path="hn_stories.db", output_dir="summaries"):
        """Initialize with database path and output directory"""
        self.db_path = db_path
        self.output_dir = output_dir
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        })
        
        # Create output directory if it doesn't exist
        os.makedirs(self.output_dir, exist_ok=True)
        
        # Create subdirectories for story and comment summaries
        self.story_dir = os.path.join(self.output_dir, "stories")
        self.comment_dir = os.path.join(self.output_dir, "comments")
        os.makedirs(self.story_dir, exist_ok=True)
        os.makedirs(self.comment_dir, exist_ok=True)
    
    def get_unsummarized_stories(self, story_type="top", limit=10):
        """Get stories that haven't been summarized yet"""
        with sqlite3.connect(self.db_path) as conn:
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            
            cursor.execute('''
                SELECT id, title, url, domain, comments_url
                FROM stories
                WHERE type = ?
                ORDER BY ranking
                LIMIT ?
            ''', (story_type, limit))
            
            stories = []
            for row in cursor.fetchall():
                story_id = row['id']
                story_summary_path = os.path.join(self.story_dir, f"{story_id}.txt")
                comment_summary_path = os.path.join(self.comment_dir, f"{story_id}.txt")
                
                # Only include stories that haven't been summarized
                if not os.path.exists(story_summary_path) or not os.path.exists(comment_summary_path):
                    stories.append(dict(row))
            
            return stories
    
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
            
            # 1. Try to find article tag or main content div
            article = soup.find('article') or soup.find('main') or soup.find(id='content') or soup.find(class_='content')
            if article:
                main_content = article.get_text(separator='\n', strip=True)
            
            # 2. If no specific content container, just get body text
            if not main_content:
                main_content = soup.body.get_text(separator='\n', strip=True)
            
            # 3. Clean up the content (remove excessive newlines, etc.)
            main_content = '\n'.join(line.strip() for line in main_content.splitlines() if line.strip())
            
            # 4. If content is too long, truncate it
            if len(main_content) > 15000:
                main_content = main_content[:15000] + "...\n[Content truncated due to length]"
            
            return main_content
        except Exception as e:
            return f"Error fetching content: {str(e)}"
    
    def fetch_comments(self, comments_url):
        """Fetch and extract comments from a HN discussion URL"""
        try:
            response = self.session.get(comments_url, timeout=10)
            if not response.ok:
                return f"Failed to fetch comments: HTTP {response.status_code}"
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # On HN, comments are in a table with class 'comment-tree'
            comment_section = soup.find('table', class_='comment-tree')
            if not comment_section:
                return "No comments found or unable to parse comment section."
            
            # Extract comments - on HN they're typically in tags with class 'commtext'
            comments = comment_section.find_all(class_='commtext')
            
            combined_comments = ""
            for i, comment in enumerate(comments[:20]):  # Limit to 20 comments
                comment_text = comment.get_text(strip=True)
                combined_comments += f"Comment {i+1}:\n{comment_text}\n\n"
            
            if not combined_comments:
                return "No comments found."
            
            # Truncate if too long
            if len(combined_comments) > 15000:
                combined_comments = combined_comments[:15000] + "...\n[Comments truncated due to length]"
            
            return combined_comments
        except Exception as e:
            return f"Error fetching comments: {str(e)}"
    
    def summarize_with_ollama(self, content, prompt_type):
        """Use Ollama to summarize content"""
        try:
            # Define prompts based on content type
            if prompt_type == "story":
                prompt = f"Summarize the following article in 2-3 paragraphs, highlighting the key points and insights:\n\n{content}"
            else:  # comments
                prompt = f"Summarize the following discussion comments in 2-3 paragraphs, highlighting the main opinions, insights, and helpful information:\n\n{content}"
            
            # Make request to Ollama API
            response = requests.post(
                "http://localhost:11434/api/generate",
                json={
                    "model": "llama3", # Adjust model name as needed
                    "prompt": prompt,
                    "stream": False
                },
                timeout=60
            )
            
            if response.status_code == 200:
                result = response.json()
                return result.get("response", "No summary generated")
            else:
                return f"Failed to get summary: HTTP {response.status_code}"
        except Exception as e:
            return f"Error generating summary: {str(e)}"
    
    def process_story(self, story):
        """Process a single story - fetch content, summarize, and save"""
        story_id = story['id']
        print(f"Processing story {story_id}: {story['title']}")
        
        # Paths for summary files
        story_summary_path = os.path.join(self.story_dir, f"{story_id}.txt")
        comment_summary_path = os.path.join(self.comment_dir, f"{story_id}.txt")
        
        # Process story content if needed
        if not os.path.exists(story_summary_path) and story['url']:
            print(f"  Fetching content from {story['url']}")
            content = self.fetch_story_content(story['url'])
            
            if len(content) > 200:  # Only summarize if we have substantial content
                print("  Summarizing content with Ollama...")
                summary = self.summarize_with_ollama(content, "story")
                
                # Save the summary
                with open(story_summary_path, 'w', encoding='utf-8') as f:
                    f.write(f"Title: {story['title']}\n")
                    f.write(f"URL: {story['url']}\n\n")
                    f.write(summary)
                
                print(f"  Saved story summary to {story_summary_path}")
            else:
                print("  Not enough content to summarize")
                # Save the minimal content
                with open(story_summary_path, 'w', encoding='utf-8') as f:
                    f.write(f"Title: {story['title']}\n")
                    f.write(f"URL: {story['url']}\n\n")
                    f.write("Insufficient content to summarize.")
        
        # Process comments if needed
        if not os.path.exists(comment_summary_path) and story['comments_url']:
            print(f"  Fetching comments from {story['comments_url']}")
            comments = self.fetch_comments(story['comments_url'])
            
            if len(comments) > 200:  # Only summarize if we have substantial comments
                print("  Summarizing comments with Ollama...")
                summary = self.summarize_with_ollama(comments, "comments")
                
                # Save the summary
                with open(comment_summary_path, 'w', encoding='utf-8') as f:
                    f.write(f"Title: {story['title']}\n")
                    f.write(f"Comments URL: {story['comments_url']}\n\n")
                    f.write(summary)
                
                print(f"  Saved comment summary to {comment_summary_path}")
            else:
                print("  Not enough comments to summarize")
                # Save the minimal content
                with open(comment_summary_path, 'w', encoding='utf-8') as f:
                    f.write(f"Title: {story['title']}\n")
                    f.write(f"Comments URL: {story['comments_url']}\n\n")
                    f.write("Insufficient comments to summarize.")
        
        # Sleep to be nice to APIs and servers
        time.sleep(1)
    
    def run(self, story_type="top", limit=10):
        """Run the summarization process"""
        print(f"Looking for {story_type} stories to summarize (limit: {limit})...")
        stories = self.get_unsummarized_stories(story_type, limit)
        
        if not stories:
            print("No new stories to summarize.")
            return
        
        print(f"Found {len(stories)} stories to process.")
        
        for story in stories:
            self.process_story(story)
            print("-" * 40)
        
        print(f"Completed summarization of {len(stories)} stories.")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description='Summarize Hacker News stories and comments')
    parser.add_argument('--type', choices=['top', 'new', 'best'], default='top',
                        help='Type of stories to summarize (default: top)')
    parser.add_argument('--limit', type=int, default=10,
                        help='Maximum number of stories to process (default: 10)')
    parser.add_argument('--db', default='hn_stories.db',
                        help='Path to SQLite database (default: hn_stories.db)')
    parser.add_argument('--output', default='summaries',
                        help='Output directory for summaries (default: summaries)')
    
    args = parser.parse_args()
    
    summarizer = StorySummarizer(db_path=args.db, output_dir=args.output)
    summarizer.run(story_type=args.type, limit=args.limit)