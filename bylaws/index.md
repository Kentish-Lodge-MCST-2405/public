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

<h2>{{ group.name }}</h2>

{% assign subgroups = group.items | group_by: 'subcategory' | sort: 'name' %}
{% for sg in subgroups %}
{% assign subname = sg.name | default: '' %}
{% if subname != '' %}
<h3>{{ subname }}</h3>
{% endif %}

{% assign with_order = sg.items | where_exp: 'i', 'i.order' | sort: 'order' %}
{% assign without_order = sg.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
<ul>
{% for bylaw in with_order %}
  {% unless bylaw.title == subname %}
  <li><a href="{{ bylaw.url | relative_url }}">{{ bylaw.title | default: bylaw.name }}</a></li>
  {% endunless %}
{% endfor %}
{% for bylaw in without_order %}
  {% unless bylaw.title == subname %}
  <li><a href="{{ bylaw.url | relative_url }}">{{ bylaw.title | default: bylaw.name }}</a></li>
  {% endunless %}
{% endfor %}
</ul>

{% endfor %}

{% endfor %}
{% endif %}
