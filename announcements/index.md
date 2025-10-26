---
layout: default
title: Announcements
permalink: /announcements/
---

# Current Announcements

{% assign now = site.time | date: '%s' %}
{% assign pages = site.announcements | sort: 'date' | reverse %}
{% assign current = '' | split: '' %}
{% for a in pages %}
  {% assign ends = a.ends_on | default: a.date %}
  {% assign ends_s = ends | date: '%s' %}
  {% if (a.status != 'archived' and a.status != 'archive') and ends_s >= now %}
    {% assign current = current | push: a %}
  {% endif %}
{% endfor %}
{% if current.size == 0 %}
_No announcements yet._
{% else %}
<ul>
  {% for a in current %}
  <li>
    <a href="{{ a.url | relative_url }}">{{ a.title }}</a>
    {% if a.date %}<small>({{ a.date | date: '%b %d, %Y' }})</small>{% endif %}
  </li>
  {% endfor %}
</ul>
{% endif %}

<p><a class="btn secondary" href="{{ '/announcements/archive/' | relative_url }}">View past announcements</a></p>
