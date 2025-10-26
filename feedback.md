---
layout: default
title: Feedback
---

<h2>Submit Feedback</h2>

<p>Use the form below to submit feedback, report a problem, or suggest an improvement. Your feedback will be submitted as a GitHub issue, which will allow the council and management to track and address it.</p>

<form id="feedback-form">
  <label for="subject">Subject:</label><br>
  <input type="text" id="subject" name="subject" required style="width: 100%; padding: 8px; margin-bottom: 10px;"><br>

  <label for="feedback">Feedback:</label><br>
  <textarea id="feedback" name="feedback" rows="10" required style="width: 100%; padding: 8px;"></textarea><br><br>

  <button type="submit">Submit Feedback</button>
</form>

<script>
  document.getElementById('feedback-form').addEventListener('submit', function(event) {
    event.preventDefault();

    const subject = document.getElementById('subject').value;
    const feedback = document.getElementById('feedback').value;

    const repoUrl = 'https://github.com/Kentish-Lodge-MCST-2405/public';
    const issueUrl = `${repoUrl}/issues/new?title=${encodeURIComponent(subject)}&body=${encodeURIComponent(feedback)}&labels=feedback`;

    window.open(issueUrl, '_blank');
  });
</script>
