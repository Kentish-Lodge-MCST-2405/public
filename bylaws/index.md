---
layout: default
title: Bylaws
---

# Bylaws

This page lists the **bylaws** of MCST Plan No. 2405. Certain **AGM ordinary resolutions** and **policies** that affect how bylaws are administered (for example, interest on late payment and recovery of legal fees) are recorded separately but are referenced here for convenience.

## Related AGM Resolution and Policy (Not Bylaws)

- [AGM Ordinary Resolution  Interest on Late Payment and Recovery of Legal Fees]({{ site.baseurl }}/policies/finance-late-payment-resolution/)
- [Policy  Interest on Late Payment and Recovery of Legal Fees]({{ site.baseurl }}/policies/finance-late-payment-policy/)

## BYLAW

{% assign empty_array = '' | split: '' %}
{% assign bylaws_docs = site.bylaws | default: site.collections['bylaws'].docs | default: empty_array %}
{% assign groups = bylaws_docs | group_by: 'category' | sort: 'name' %}
{% if groups.size == 0 %}
_No bylaws yet._
{% else %}
{% for group in groups %}
### {{ group.name }}

{% assign subgroups = group.items | group_by: 'subcategory' | sort: 'name' %}
{% for sg in subgroups %}
{% assign subname = sg.name | default: '' %}
{% if subname != '' %}
#### {{ subname }}
{% endif %}
{% assign with_order = sg.items | where_exp: 'i', 'i.order' | sort_natural: 'order' %}
{% assign without_order = sg.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
{% for bylaw in with_order %}
  {% unless bylaw.title == subname %}
- {{ bylaw.title | default: bylaw.name }}{% endunless %}
{% endfor %}
{% for bylaw in without_order %}
  {% unless bylaw.title == subname %}
- {{ bylaw.title | default: bylaw.name }}{% endunless %}
{% endfor %}
{% endfor %}
{% endfor %}
{% endif %}
