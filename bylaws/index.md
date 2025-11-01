---
layout: default
title: Bylaws
---

# Bylaws

{% assign groups = site.bylaws | group_by: 'category' | sort: 'name' %}
{% if groups.size == 0 %}
_No bylaws yet._
{% else %}
{% for group in groups %}
## {{ group.name }}
{% assign with_order = group.items | where_exp: 'i', 'i.order' | sort: 'order' %}
{% assign without_order = group.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
{% for bylaw in with_order %}
- [{{ bylaw.title | default: bylaw.name }}]({{ bylaw.url | relative_url }})
{% endfor %}
{% for bylaw in without_order %}
- [{{ bylaw.title | default: bylaw.name }}]({{ bylaw.url | relative_url }})
{% endfor %}
{% endfor %}
{% endif %}
