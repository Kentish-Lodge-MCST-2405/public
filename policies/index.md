---
layout: default
title: Policies
---

# Policies

{% assign groups = site.policies | group_by: 'category' | sort: 'name' %}
{% if groups.size == 0 %}
_No policies yet._
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
  {% for p in with_order %}
    {% unless p.title == subname %}
    <li><a href="{{ p.url | relative_url }}">{{ p.title | default: p.name }}</a></li>
    {% endunless %}
  {% endfor %}
  {% for p in without_order %}
    {% unless p.title == subname %}
    <li><a href="{{ p.url | relative_url }}">{{ p.title | default: p.name }}</a></li>
    {% endunless %}
  {% endfor %}
</ul>

{% endfor %}

{% endfor %}
{% endif %}
