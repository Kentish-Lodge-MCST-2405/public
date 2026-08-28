---
layout: default
title: Bylaws
---

# Bylaws

This page lists the **bylaws** of MCST Plan No. 2405. Certain **AGM ordinary resolutions** and **policies** that affect how bylaws are administered (for example, interest on late payment and recovery of legal fees) are recorded separately but are referenced here for convenience.

## Related AGM Resolution and Policy (Not Bylaws)

- [AGM Ordinary Resolution  Interest on Late Payment and Recovery of Legal Fees]({{ site.baseurl }}/policies/finance-late-payment-resolution/)
- [Policy  Interest on Late Payment and Recovery of Legal Fees]({{ site.baseurl }}/policies/finance-late-payment-policy/)

## BYLAW

{% assign empty_array = '' | split: '' %}
{% assign bylaws_docs = site.bylaws | default: site.collections['bylaws'].docs | default: empty_array %}
{% if bylaws_docs.size == 0 %}
_No bylaws yet._
{% else %}
{% assign cats = bylaws_docs | group_by: 'category' | sort: 'name' %}
{% for g in cats %}
### {{ g.name }}

{% assign subs = g.items | group_by: 'subcategory' | sort: 'name' %}
{% for sg in subs %}
{% if sg.name != '' %}
#### {{ sg.name }}

{% endif %}
{% assign with_order = sg.items | where_exp: 'i', 'i.order' | sort_natural: 'order' %}
{% assign without_order = sg.items | where_exp: 'i', 'i.order == nil' | sort: 'title' %}
{% for b in with_order %}
{% unless b.title == sg.name %}
- [{{ b.title | default: b.name }}]({{ b.url | relative_url }})
{% endunless %}
{% endfor %}
{% for b in without_order %}
{% unless b.title == sg.name %}
- [{{ b.title | default: b.name }}]({{ b.url | relative_url }})
{% endunless %}
{% endfor %}

{% endfor %}
{% endfor %}
{% endif %}
