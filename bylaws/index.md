---
layout: default
title: Bylaws
---

# Bylaws

{% assign items = site.bylaws | sort: 'title' %}
{% if items.size == 0 %}
_No bylaws yet._
{% else %}
{% for bylaw in items %}
- [{{ bylaw.title | default: bylaw.name }}]({{ bylaw.url | relative_url }})
{% endfor %}
{% endif %}
