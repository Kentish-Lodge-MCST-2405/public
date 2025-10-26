---
layout: default
title: Announcement Archive
---

# Past Announcements

{% assign now = site.time | date: '%s' %}
{% assign items = site.announcements | sort: 'date' | reverse %}
{% assign past = '' | split: '' %}
{% for a in items %}
  {% assign ends = a.ends_on | default: a.date %}
  {% assign ends_s = ends | date: '%s' %}
  {% if ends_s < now or a.status == 'archived' %}
    {% assign past = past | push: a %}
  {% endif %}
{% endfor %}

{% if past.size == 0 %}
_No past announcements yet._
{% else %}
<ul>
  {% for a in past %}
  <li>
    <a href="{{ a.url | relative_url }}">{{ a.title }}</a>
    <small>({{ a.date | date: '%b %d, %Y' }})</small>
  </li>
  {% endfor %}
</ul>
{% endif %}
