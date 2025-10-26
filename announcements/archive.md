---
layout: default
title: Announcement Archive
permalink: /announcements/archive/
---

# Past Announcements

{% assign now = site.time | date: '%s' %}
{% assign past = '' | split: '' %}
{% assign ann = site.announcements | sort: 'date' | reverse %}
{% for a in ann %}
  {% assign ends = a.ends_on | default: a.date %}
  {% assign ends_s = ends | date: '%s' %}
  {% if ends_s < now or a.status == 'archived' or a.status == 'archive' %}
    {% assign past = past | push: a %}
  {% endif %}
{% endfor %}

{% if past.size == 0 %}
_No past announcements yet._
{% else %}
<ul>
  {% assign past_sorted = past | sort: 'date' | reverse %}
  {% for a in past_sorted %}
  <li>
    <a href="{{ a.url | relative_url }}">{{ a.title }}</a>
    <small>({{ a.date | date: '%b %d, %Y' }})</small>
  </li>
  {% endfor %}
</ul>
{% endif %}
