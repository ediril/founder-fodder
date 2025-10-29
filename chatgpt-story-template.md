Apply the template below EXACTLY to “Steve Ells"


### Story Prompt Template

**Input:** Name of a person and/or brand: **{NAME}**

---

#### **Instructions**

1. **Research {NAME}** (and the business they founded, if applicable). Collect key facts:  
   - early dream(s)
   - their **scrappiest beginning** (financial hardship, personal struggle, very low point, unusual childhood)  
   - ridiculed, rejected, ignored, or unexpected developments
   - setbacks/failures
   - creative, unique, unexpected solution to challenges
   - significant, unusual, shocking wins
   - current status/scale of success (net worth, global reach, brand size).

2. **Distil the story** into a clear narrative arc
    - Focus on emotional impact rather than facts. Evoke emotion, make readers want to flip pages
    - Stick to ONE major story in your research, ignore other smaller ones.
    - **Page 0 (Hook):**  
        - 1 sentence (2 at most)
        - Must explicitly connect a **scrappy beginning** (small, unusual, curious, misfit, rebelious) to a **numeric success metric** today (net worth, revenue, valuation, box office, awards).  
        - Example: “From broke kid to $150M Oscar winner.”  
    - **Pages 1..N:** 
        - Describe key elements in the story based on your research.
        - Make sure you mention names, relevant years, places.
    - **N is 6 to 11:** Pick number of pages based on the complexity of the story.
    - Add a page to talk about current state (wealth, scale, or cultural reach).
    - Add a page for takeaways, insights from the story. Do not mention the words "takeaway", "insight" in the text

3. **For every page (1..N+2):**  
    - First sentence on each page moves the story forward.  
    - Each sentence is 10 words or less.
    - Punchy, emotion evoking voice, direct. Nothing poetic or rhyming!
    - Some sentences can be short, terse for effect. 
    - Alternate short sentences with long ones for style.
    - **2 to 5 sentences** per page (except Page 0)
    - Alternate sentence count on consequitive pages to break repetitive pattern.
    - **At most two consequitive pages may have the same sentence count**.

4. **Validate:**  
   - **Numeric metric:** verify via reputable sources (Forbes, Celebrity Net Worth, SEC filings, Box Office Mojo, etc.).  
   - **Scrappy claim:** verify via interviews, biographies, or major outlets.  
   - If a value or claim cannot be confidently verified, don't use it.  
   - **Sentence-count audit:** before finalizing, confirm the page-by-page counts satisfy Rule 3.
   - If anything fails validation, **replace or revise** before output.

5. **Image search phrases**: 
- Provide the following as separate entries to search for images to use on the pages:
  - the name of the person the story is about
  - the brand/company the story is about
  - a few of the key years in the story (no more than 3, definitely mention the beginning year)

6. **References:** 
    - list all sources used to put together the story
    - include links to web pages, exclude links to images

7. **Output**:
    - **Remove all `:contentReference` markers and incline citation tags** from text and URLs.
    - Ensure all URLs are **clean, direct, and permanent**.  
    - Keep text fully human-readable (no internal references, hidden formatting, or metadata).  
    - Maintain consistent YAML indentation and syntax.
    - Return YAML in a code block, using the following format:
```yaml
pages:
  - text_0:
      - "[hook sentence, includes scrappy-beginning phrase and one numeric metric]"

  - text_1:
      - "[sentence 1]"
      - "[sentence 2]"
      - "[sentence 3]"

  …

  - text_N:
      - "[sentence 1]"
      - "[sentence 2]"
      - "[sentence 3]"
      - "[numeric fact summarising current scale]"

images:
    - "[search phrase 1]"
    - "[search phrase 2]"
    - "..."

references:
  - "[title and URL of reference]"
  - "..."
