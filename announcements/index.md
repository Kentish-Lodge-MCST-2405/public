---
layout: default
title: Announcements
---

# Current Announcements

{% assign pages = site.pages | where_exp: 'p', "p.dir == '/announcements/'" | sort: 'date' | reverse %}
{% if pages.size == 0 %}
_No announcements yet._
{% else %}
<ul>
  {% for a in pages %}
  <li>
    <a href="{{ a.url | relative_url }}">{{ a.title }}</a>
    {% if a.date %}<small>({{ a.date | date: '%b %d, %Y' }})</small>{% endif %}
  </li>
  {% endfor %}
</ul>
{% endif %}

<p><a class="btn secondary" href="{{ '/announcements/archive/' | relative_url }}">View past announcements</a></p>
