---
layout: default
title: Policies
---

# Policies

{%- assign groups = site.policies | group_by: 'category' | sort: 'name' -%}
{% if groups.size == 0 %}
_No policies yet._
{% else %}
{%- for group in groups -%}
## {{ group.name }}
{%- assign subgroups = group.items | group_by: 'subcategory' | sort: 'name' -%}
{%- for sg in subgroups -%}
{%- assign subname = sg.name | default: '' -%}
{%- if subname != '' -%}
### {{ subname }}
{%- endif -%}
{%- for o in (0..99) -%}
  {%- for p in sg.items -%}
    {%- assign ord = p.order -%}
    {%- if ord == nil -%}
      {%- assign eff = nil -%}
      {%- if p.bylaws and p.bylaws.size > 0 -%}
        {%- assign first_key = p.bylaws[0] -%}
        {%- for b in site.bylaws -%}
          {%- assign b_slug = b.path | split:'/' | last | split:'.' | first -%}
          {%- if b_slug == first_key or b.title == first_key -%}
            {%- assign eff = b.order -%}
            {%- break -%}
          {%- endif -%}
        {%- endfor -%}
      {%- else -%}
        {%- assign self_slug = p.path | split:'/' | last | split:'.' | first -%}
        {%- for b in site.bylaws -%}
          {%- if b.policies and b.policies contains self_slug -%}
            {%- assign eff = b.order -%}
            {%- break -%}
          {%- endif -%}
        {%- endfor -%}
      {%- endif -%}
      {%- assign ord = eff -%}
    {%- endif -%}
    {%- if ord -%}
      {%- assign ord_int = ord | strip | plus: 0 -%}
      {%- if ord_int == o -%}
- [{{ p.title | default: p.name }}]({{ p.url | relative_url }})
      {%- endif -%}
    {%- endif -%}
  {%- endfor -%}
{%- endfor -%}

{%- assign unordered = '' | split: '' -%}
{%- for p in sg.items -%}
  {%- assign ord = p.order -%}
  {%- if ord == nil -%}
    {%- assign eff = nil -%}
    {%- if p.bylaws and p.bylaws.size > 0 -%}
      {%- assign first_key = p.bylaws[0] -%}
      {%- for b in site.bylaws -%}
        {%- assign b_slug = b.path | split:'/' | last | split:'.' | first -%}
        {%- if b_slug == first_key or b.title == first_key -%}
          {%- assign eff = b.order -%}
          {%- break -%}
        {%- endif -%}
      {%- endfor -%}
    {%- else -%}
      {%- assign self_slug = p.path | split:'/' | last | split:'.' | first -%}
      {%- for b in site.bylaws -%}
        {%- if b.policies and b.policies contains self_slug -%}
          {%- assign eff = b.order -%}
          {%- break -%}
        {%- endif -%}
      {%- endfor -%}
    {%- endif -%}
    {%- if eff == nil -%}
      {%- assign unordered = unordered | push: p -%}
    {%- endif -%}
  {%- endif -%}
{%- endfor -%}
{%- assign unordered = unordered | sort: 'title' -%}
{%- for p in unordered -%}
- [{{ p.title | default: p.name }}]({{ p.url | relative_url }})
{%- endfor -%}
{%- endfor -%}
{% endif %}
