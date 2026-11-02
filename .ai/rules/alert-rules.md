---
paths:
  - 'resources/views/filament/dashboard/alert-rules/**'
---

# Alert Rules

## The product table's subject control: switched-on rows, inert template, ~1.2-1.5 KB a row
Each product row of a paid owner carries a "Custom subject"/"Edit" control (Alpine `subjectOpen`, separate from the location dropdown's `open`); the text input and the product's placeholder helper text (App\Support\SubjectPlaceholders::helperText()) sit inside an inert `<template x-if="on && subjectOpen">`, so only the closed control is live DOM and a switched-off row has no input. It adds about 1.2-1.5 KB of markup per row (roughly 170-215 KB on the 143 Weather Alerts rows) on top of the table's ~1.9 MB; do not move to a Repeater or per-row Livewire fields. Free owners get no control. The control's aria-label starts with its visible words ("Custom subject for X"/"Edit subject for X").
