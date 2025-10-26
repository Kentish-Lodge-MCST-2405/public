---
layout: default
title: Kentish Lodge MCST
---

<section class="hero">
  <div class="container">
    <h1>Welcome to Kentish Lodge MCST</h1>
    <p>Your hub for bylaws, policies, and community feedback.</p>
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
      <h3>Announcements</h3>
      <p>Latest notices from the council and managing agent.</p>
      {% assign pages_sorted = site.pages | sort: 'date' | reverse %}
      {% assign shown = 0 %}
      <ul>
        {% for a in pages_sorted %}
          {% if a.url contains '/announcements/' and a.url != '/announcements/' and shown < 3 %}
            <li><a href="{{ a.url | relative_url }}">{{ a.title }}</a></li>
            {% assign shown = shown | plus: 1 %}
          {% endif %}
        {% endfor %}
      </ul>
      <p><a href="{{ '/announcements/' | relative_url }}">All announcements →</a></p>
    </div>
    <div class="card">
      <h3>Give Feedback</h3>
      <p>Share suggestions, report issues, or request changes. Your input improves our community.</p>
      <p><a href="{{ '/feedback/' | relative_url }}">Send feedback →</a></p>
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
