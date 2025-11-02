---
layout: default
title: Policies
---

# Policies

## POLICY

{% assign groups = site.policies | group_by: 'category' %}
{% assign groups_with_order = "" | split: "" %}
{% assign groups_without_order = "" | split: "" %}
{% for group in groups %}
  {% assign min_order = 9999 %}
  {% assign has_order = false %}
  {% for item in group.items %}
    {% if item.order %}
      {% assign order_num = item.order | slice: 0, 2 | plus: 0 %}
      {% if order_num < min_order %}
        {% assign min_order = order_num %}
        {% assign has_order = true %}
      {% endif %}
    {% endif %}
  {% endfor %}
  {% if has_order %}
    {% assign group_with_order = group | merge: '{"category_order": "' | append: min_order | append: '"}' %}
    {% assign groups_with_order = groups_with_order | push: group_with_order %}
  {% else %}
    {% assign groups_without_order = groups_without_order | push: group %}
  {% endif %}
{% endfor %}
{% assign sorted_groups = groups_with_order | sort: 'category_order' | concat: groups_without_order | sort: 'name' %}
{% assign groups = sorted_groups %}
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
