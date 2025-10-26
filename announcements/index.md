---
layout: default
title: Announcements
---

# Current Announcements

{% assign now = site.time | date: '%s' %}
{% assign items = site.announcements | sort: 'date' | reverse %}
{% assign current = '' | split: '' %}
{% for a in items %}
  {% assign ends = a.ends_on | default: a.date %}
  {% assign ends_s = ends | date: '%s' %}
  {% if ends_s >= now and a.status != 'archived' %}
    {% assign current = current | push: a %}
  {% endif %}
{% endfor %}

{% if current.size == 0 %}
_No active announcements._
{% else %}
<ul>
  {% for a in current %}
  <li>
    <a href="{{ a.url | relative_url }}">{{ a.title }}</a>
    <small>({{ a.date | date: '%b %d, %Y' }})</small>
  </li>
  {% endfor %}
</ul>
{% endif %}

<p><a class="btn secondary" href="{{ '/announcements/archive/' | relative_url }}">View past announcements</a></p>
