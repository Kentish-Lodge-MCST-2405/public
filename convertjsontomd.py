import json
import os
import re
import tempfile
from datetime import datetime

def create_slug(text):
    """Convert text to a slug for filenames"""
    slug = text.lower()
    slug = re.sub(r'[^a-z0-9]+', '-', slug)
    slug = slug.strip('-')
    return slug

def write_text_file(file_path, content):
    directory = os.path.dirname(file_path) or "."
    os.makedirs(directory, exist_ok=True)
    with tempfile.NamedTemporaryFile('w', delete=False, dir=directory, encoding='utf-8', newline='\n') as tmp:
        tmp.write(content)
        tmp_path = tmp.name
    os.replace(tmp_path, file_path)

def process_json_data(json_file_path):
    # Read JSON file
    with open(json_file_path, 'r', encoding='utf-8') as file:
        data = json.load(file)

    # Create directories if they don't exist
    os.makedirs('_bylaws', exist_ok=True)
    os.makedirs('_policies', exist_ok=True)
    
    # Group by subcategory and compute hierarchical order CC.SS
    subcategories = {}
    category_index_map = {}
    subcategory_index_map = {}  # {category: {subcategory: idx}}
    next_category_idx = 1
    for item in data:
        category = item['category']
        subcat = item['subcategory']

        # Assign category index if first time seen
        if category not in category_index_map:
            category_index_map[category] = next_category_idx
            next_category_idx += 1
            subcategory_index_map[category] = {}

        # Assign subcategory index within this category if first time seen
        if subcat not in subcategory_index_map[category]:
            subcategory_index_map[category][subcat] = len(subcategory_index_map[category]) + 1

        # Collect items per subcategory
        if subcat not in subcategories:
            subcategories[subcat] = []
        subcategories[subcat].append(item)
    
    # Process each subcategory
    for subcategory, items in subcategories.items():
        slug = create_slug(subcategory)
        # Derive file-level order code CC.SS using first item's category/subcategory
        first_item = items[0]
        cat = first_item['category']
        cat_idx = category_index_map[cat]
        sub_idx = subcategory_index_map[cat][subcategory]
        file_order_code = f"{cat_idx:02d}.{sub_idx:02d}"
        
        # Create bylaw file
        create_bylaw_file(subcategory, items, slug, file_order_code)
        
        # Create policy file
        create_policy_file(subcategory, items, slug, file_order_code)

def create_bylaw_file(subcategory, items, slug, file_order_code):
    """Create a bylaw markdown file for the subcategory"""
    
    # Extract rule numbers and titles for the front matter
    rule_titles = [item['title'] for item in items]
    
    # Build the content
    content_parts = []
    for item in items:
        # Extract rule number from title (e.g., "Rule 36" from "Rule 36 - Fire Emergency Lifts")
        rule_match = re.match(r'Rule\s+(\d+)', item['title'])
        rule_num = rule_match.group(1) if rule_match else ""
        
        content_parts.append(f"## {item['title']}")
        content_parts.append("")
        content_parts.append(item['bylaw_text'])
        content_parts.append("")
    
    content = "\n".join(content_parts)
    
    # Front matter
    front_matter = f"""---
title: {subcategory} Bylaws
category: {subcategory}
effective: {datetime.now().strftime('%Y-%m-%d')}
version: 1.0
order: "{file_order_code}"
policies:
  - {slug}-policy
---

"""
    
    # Write file
    file_path = f"_bylaws/{slug}.md"
    write_text_file(file_path, front_matter + content)
    
    print(f"Wrote: {file_path}")

def create_policy_file(subcategory, items, slug, file_order_code):
    """Create a policy markdown file for the subcategory"""
    
    # Build the content
    content_parts = []
    
    for item in items:
        # Remove the "## Implementation Details" header and use the rule title instead
        implementation_content = item['implementation'].replace('## Implementation Details', '').strip()
        
        content_parts.append(f"## {item['title']}")
        content_parts.append("")
        content_parts.append(implementation_content)
        content_parts.append("")
    
    content = "\n".join(content_parts)
    
    # Front matter
    front_matter = f"""---
title: {subcategory} Policy
category: {subcategory}
effective: {datetime.now().strftime('%Y-%m-%d')}
version: 1.0
order: "{file_order_code}"
bylaws:
  - {slug}
---

"""
    
    # Write file
    file_path = f"_policies/{slug}-policy.md"
    write_text_file(file_path, front_matter + content)
    
    print(f"Wrote: {file_path}")

def main():
    # Assuming the JSON file is named 'bylaws.json' in the same directory
    json_file_path = 'extractedbylaws.json'
    
    if not os.path.exists(json_file_path):
        print(f"Error: JSON file '{json_file_path}' not found!")
        return
    
    try:
        process_json_data(json_file_path)
        print("\nAll files generated successfully!")
        print("Files created in '_bylaws/' and '_policies/' directories")
    except Exception as e:
        print(f"Error processing JSON data: {e}")

if __name__ == "__main__":
    main()