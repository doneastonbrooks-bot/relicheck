# Conversational Take

**Where it lives:** Append `?chat=1` to any public share link.

**Outcome.** Let respondents answer in their own words.

Each question appears in plain language; the respondent replies naturally ("agree," "5," "I love this"); the structured value gets pulled out and confirmed back in one sentence before the next question.

**More detail.** Append `?chat=1` to any survey's public share link and the take page becomes a chat. Each reply gets mapped to the right Likert position, single-choice option, or multi-choice set, and the result is confirmed back so the respondent can correct it if needed. Skip logic, autosave with invitation tokens, and the same submit endpoint as the form mode all run unchanged; the standard form mode keeps working at the bare URL, so chat mode is purely additive and opt-in.

**Guardrails.** The endpoint is rate-limited to 240 calls per hour per IP. Each call uses a 400-token output budget and a question-shape-constrained prompt. Server-side validation clamps Likert values to the survey's scale and rejects out-of-range single-choice indices.
