---
layout: default
title: Policies
---

# Policies

## POLICY

{% assign groups = site.policies | group_by: 'category' | sort: 'name' %}
{% if groups.size == 0 %}
_No policies yet._
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
{% for p in with_order %}
  {% unless p.title == subname %}
- {{ p.title | default: p.name }}{% endunless %}
{% endfor %}
{% for p in without_order %}
  {% unless p.title == subname %}
- {{ p.title | default: p.name }}{% endunless %}
{% endfor %}
{% endfor %}
{% endfor %}
{% endif %}
