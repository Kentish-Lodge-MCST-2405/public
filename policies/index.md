---
layout: default
title: Policies
---

# Policies

{% assign items = site.policies | sort: 'title' %}
{% if items.size == 0 %}
_No policies yet._
{% else %}
{% for policy in items %}
- [{{ policy.title | default: policy.name }}]({{ policy.url | relative_url }})
{% endfor %}
{% endif %}
