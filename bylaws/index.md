---
layout: default
title: Bylaws
---

# Bylaws

## BYLAW

{% assign groups = site.bylaws | group_by: 'category' | sort: 'name' %}
{% if groups.size == 0 %}
_No bylaws yet._
{% else %}
{% for group in groups %}
### {{ group.name }}

{% assign subgroups = group.items | group_by: 'subcategory' | sort: 'name' %}
{% for sg in subgroups %}
{% assign subname = sg.name | default: '' %}
{% if subname != '' %}
#### {{ subname }}
{% endif %}
{% assign with_order = sg.items | where_exp: 'i', 'i.order' | sort_natural: 'order' %}
{% assign without_order = sg.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
{% for bylaw in with_order %}
  {% unless bylaw.title == subname %}
- {{ bylaw.title | default: bylaw.name }}{% endunless %}
{% endfor %}
{% for bylaw in without_order %}
  {% unless bylaw.title == subname %}
- {{ bylaw.title | default: bylaw.name }}{% endunless %}
{% endfor %}
{% endfor %}
{% endfor %}
{% endif %}
