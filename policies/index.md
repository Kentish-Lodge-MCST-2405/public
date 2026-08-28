---
layout: default
title: Policies
---

# Policies

## POLICY

{% assign empty_array = '' | split: '' %}
{% assign policies_docs = site.policies | default: site.collections['policies'].docs | default: empty_array %}
{% if policies_docs.size == 0 %}
_No policies yet._
{% else %}
{% assign cats = policies_docs | group_by: 'category' | sort: 'name' %}
{% for g in cats %}
### {{ g.name }}

{% assign with_order = g.items | where_exp: 'i', 'i.order' | sort_natural: 'order' %}
{% assign without_order = g.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
{% for p in with_order %}
- [{{ p.title | default: p.name }}]({{ p.url | relative_url }})
{% endfor %}
{% for p in without_order %}
- [{{ p.title | default: p.name }}]({{ p.url | relative_url }})
{% endfor %}

{% endfor %}
{% endif %}
