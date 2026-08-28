---
layout: default
title: Forms
description: Application forms for Kentish Lodge residents — fill and submit online, or print the blank official form.
---

# Forms

**[Fill & submit a form online →](/forms/apply.html)** — fill in your browser, sign on screen, attach documents, and email it straight to the Management Office. Each form page below also links to its online version and a printable blank copy.

## FORMS

{% assign empty_array = '' | split: '' %}
{% assign forms_docs = site.forms | default: site.collections['forms'].docs | default: empty_array %}
{% if forms_docs.size == 0 %}
_No forms yet._
{% else %}
{% assign cats = forms_docs | group_by: 'category' | sort: 'name' %}
{% for g in cats %}
### {{ g.name }}

{% assign with_order = g.items | where_exp: 'i', 'i.order' | sort_natural: 'order' %}
{% assign without_order = g.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
{% for f in with_order %}
- [{{ f.title | default: f.name }}]({{ f.url | relative_url }})
{% endfor %}
{% for f in without_order %}
- [{{ f.title | default: f.name }}]({{ f.url | relative_url }})
{% endfor %}

{% endfor %}
{% endif %}
