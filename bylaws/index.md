---
layout: default
title: Bylaws
---

# Bylaws

{%- assign groups = site.bylaws | group_by: 'category' | sort: 'name' -%}
{% if groups.size == 0 %}
_No bylaws yet._
{% else %}
{%- for group in groups -%}
## {{ group.name }}
{%- assign subgroups = group.items | group_by: 'subcategory' | sort: 'name' -%}
{%- for sg in subgroups -%}
{%- assign subname = sg.name | default: '' -%}
{%- if subname != '' -%}
### {{ subname }}
{%- endif -%}
{%- assign with_order = sg.items | where_exp: 'i', 'i.order' | sort: 'order' -%}
{%- assign without_order = sg.items | where_exp: 'i', 'i.order == nil' | sort: 'title' -%}
{%- for bylaw in with_order -%}
- [{{ bylaw.title | default: bylaw.name }}]({{ bylaw.url | relative_url }})
{%- endfor -%}
{%- for bylaw in without_order -%}
- [{{ bylaw.title | default: bylaw.name }}]({{ bylaw.url | relative_url }})
{%- endfor -%}
{%- endfor -%}
{%- endfor -%}
{% endif %}
