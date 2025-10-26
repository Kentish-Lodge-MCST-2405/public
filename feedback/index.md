---
layout: default
title: General Feedback
---

We welcome your ideas, questions, and change requests.

- For feedback on a specific bylaw or policy, use the Feedback buttons on that item page.
- For general suggestions, use the button below to open a new issue.

{% assign repo = site.github.repository_url | default: '' %}
{% if repo != '' %}
<p>
  <a class="btn" href="{{ repo }}/issues/new?title={{ 'General feedback' | uri_escape }}&labels=feedback">Open Feedback Issue</a>
  <a class="btn secondary" href="{{ repo }}/issues">View Existing Issues</a>
</p>
{% else %}
<p>Issue links are unavailable locally. Deploy to GitHub Pages to enable feedback.</p>
{% endif %}
