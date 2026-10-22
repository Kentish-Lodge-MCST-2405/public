---
layout: default
title: Kentish Lodge MCST
description: Official bylaws, policies, announcements, and resident forms for Kentish Lodge MCST (Plan No. 2405).
---

<section class="hero">
  <div class="container">
    <p class="eyebrow">MCST Plan No. 2405 · Singapore</p>
    <h1>Kentish Lodge</h1>
    <p>Official bylaws, policies, announcements and resident forms for our estate — clear, versioned and open to every resident.</p>
    <!--div class="actions">
      <a class="btn" href="{{ '/bylaws/' | relative_url }}">View Bylaws</a>
      <a class="btn" href="{{ '/policies/' | relative_url }}">Browse Policies</a>
      {% assign repo = site.github.repository_url | default: '' %}
      {% if repo != '' %}
      <a class="btn secondary" href="{{ '/feedback/' | relative_url }}">General Feedback</a>
      {% endif %}
    </div-->
  </div>
  
</section>

<div class="container">
  <div class="grid">
    <div class="card">
      <h3>Bylaws</h3>
      <p>Official rules adopted by the MCST. Clear, versioned, and easy to navigate.</p>
      <p><a href="{{ '/bylaws/' | relative_url }}">Explore bylaws →</a></p>
    </div>
    <div class="card">
      <h3>Policies</h3>
      <p>How bylaws are implemented day-to-day. Practical guidance for residents and management.</p>
      <p><a href="{{ '/policies/' | relative_url }}">Browse policies →</a></p>
    </div>
    <div class="card">
      <h3>Report &amp; Track Issues</h3>
      <p>Something broken or not right? Report it online, keep your reference and PIN, and follow it through to resolution — every report gets a record.</p>
      <p><a href="{{ '/tools/issues.html' | relative_url }}">Report an issue →</a></p>
      <p><a href="{{ '/tools/issues.html' | relative_url }}">Track / follow up →</a></p>
    </div>
    <div class="card">
      <h3>Forms</h3>
      <p>Application forms for residents — vehicle &amp; RFID tag registration, authorisation letters, and more.</p>
      <p><a href="{{ '/forms/' | relative_url }}">Browse forms →</a></p>
      <p><a href="{{ '/forms/apply.html' | relative_url }}">Fill a form online →</a></p>
    </div>
    <div class="card">
      <h3>Announcements</h3>
      <p>Latest notices from the council and managing agent.</p>
      {% assign empty_array = '' | split: '' %}
      {% assign now = site.time | date: '%s' %}
      {% assign announcement_docs = site.announcements | default: site.collections['announcements'].docs | default: empty_array %}
      {% assign recent_ann = announcement_docs | sort: 'date' | reverse %}
      {% assign shown = 0 %}
      <ul>
        {% for a in recent_ann %}
          {% assign ends = a.ends_on | default: a.date %}
          {% assign ends_s = ends | date: '%s' %}
          {% if (a.status != 'archived' and a.status != 'archive') and ends_s >= now and shown < 3 %}
            <li><a href="{{ a.url | relative_url }}">{{ a.title | default: a.name }}</a></li>
            {% assign shown = shown | plus: 1 %}
          {% endif %}
        {% endfor %}
      </ul>
      <p><a href="{{ '/announcements/' | relative_url }}">All announcements →</a></p>
    </div>
    <div class="card">
      <h3>Give Feedback</h3>
      <p>Suggestions and change requests on bylaws, policies or this site. (For maintenance issues, use <a href="{{ '/tools/issues.html' | relative_url }}">Report an issue</a>.)</p>
      <p><a href="{{ '/feedback/' | relative_url }}">Send feedback →</a></p>
    </div>
    <div class="card">
      <h3>Estate records (office)</h3>
      <p>Management tools: site visits &amp; inspections, issue handling, and official letters with recorded appeal rights. Key required.</p>
      <p><a href="{{ '/tools/visits.html' | relative_url }}">Site visits &amp; issues →</a></p>
      <p><a href="{{ '/tools/letters.html' | relative_url }}">Official letters →</a></p>
    </div>
    <div class="card">
      <h3>Transparency</h3>
      <p>Feedback is tracked as GitHub Issues with full history, labels, and notifications.</p>
      {% if repo != '' %}
      <p><a href="{{ repo }}/issues">View open issues →</a></p>
      {% endif %}
    </div>
  </div>
</div>
