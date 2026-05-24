-- Phase 142 test dataset: Workplace Engagement Pulse with 4 quarterly waves.
--
-- Inserts a survey owned by don.eastonbrooks@gmail.com plus 100 responses
-- distributed across Q3 2025, Q4 2025, Q1 2026, and Q2 2026 (25 per wave).
-- Each response carries a Q_CHANNEL value (Phase 41 channel tagging) so the
-- Phase 142 Trends tab auto-detects the four waves and computes wave-over-
-- wave deltas without falling back to quarterly binning.
--
-- Story the data tells:
--   - Wave 1 (Q3 2025): mean composite Likert ~4.0 (engagement strong)
--   - Wave 2 (Q4 2025): ~3.8 (small slip)
--   - Wave 3 (Q1 2026): ~3.6 (notable slip)
--   - Wave 4 (Q2 2026): ~3.4 (continued slip; the latest wave should trigger a "Slipping" verdict)
-- The construct "engagement" is tagged on every Likert item, so the per-
-- construct trend line will move alongside the composite. The narrator
-- should flag the wave-over-wave drop as significant given 25 per wave.
--
-- USAGE: select dbs15641829 in the top-left phpMyAdmin drop-down first,
-- then paste this entire SQL and run. Re-running creates a second copy
-- (slug suffix randomized).

USE dbs15641829;

SET @owner_id := (SELECT id FROM users WHERE email = 'don.eastonbrooks@gmail.com' LIMIT 1);
SET @demo_slug := 'test-trends-pulse';
SET @demo_slug := IF(
  EXISTS(SELECT 1 FROM surveys WHERE slug = @demo_slug),
  CONCAT(@demo_slug, '-', SUBSTRING(MD5(RAND()), 1, 4)),
  @demo_slug
);

-- 1. Insert the survey. Six Likert items + one open-ended; all six Likert
-- items tagged construct=engagement so the trend dashboard's per-construct
-- card lights up.
INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
VALUES (
  @owner_id,
  @demo_slug,
  'Workplace Engagement Pulse (Phase 142 test)',
  'Four-wave quarterly pulse. Use this to test the Phase 142 Trends dashboard. Hand-crafted means slip from about 4.0 in Q3 2025 to about 3.4 in Q2 2026.',
  '{"likertPoints":5,"likertLow":"Strongly disagree","likertHigh":"Strongly agree","thankYou":"Thanks for sharing your thoughts."}',
  CONCAT(
    '[',
      '{"id":"q1","type":"likert","prompt":"I feel energized when starting a new task at work.","required":true,"reverse":false,"construct":"engagement"},',
      '{"id":"q2","type":"likert","prompt":"I find my work meaningful.","required":true,"reverse":false,"construct":"engagement"},',
      '{"id":"q3","type":"likert","prompt":"I look forward to coming to work most days.","required":true,"reverse":false,"construct":"engagement"},',
      '{"id":"q4","type":"likert","prompt":"My job leaves me feeling drained.","required":true,"reverse":true,"construct":"engagement"},',
      '{"id":"q5","type":"likert","prompt":"I would describe myself as enthusiastic about my work.","required":true,"reverse":false,"construct":"engagement"},',
      '{"id":"q6","type":"likert","prompt":"I have what I need to do my job well.","required":true,"reverse":false,"construct":"engagement"},',
      '{"id":"q7","type":"open","prompt":"In one sentence, what would make your work more engaging?","required":false}',
    ']'
  ),
  1
);

SET @survey_id := LAST_INSERT_ID();

-- 2. Insert 25 responses per wave. Q_CHANNEL on every row drives the
-- Phase 142 wave detection. The vectors are tuned so each wave's mean
-- composite lands near its target.

-- ===== Wave 1: Q3 2025 (mean target ~4.0) =====
INSERT INTO responses (survey_id, submitted_at, answers) VALUES
  (@survey_id, '2025-07-12 09:14:22', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4,"q7":"Honestly things are great right now."}'),
  (@survey_id, '2025-07-15 11:30:08', '{"Q_CHANNEL":"Q3-2025","q1":5,"q2":5,"q3":4,"q4":2,"q5":5,"q6":4,"q7":"My team is fantastic."}'),
  (@survey_id, '2025-07-18 14:55:41', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":3,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-07-22 10:08:17', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":5,"q3":4,"q4":1,"q5":5,"q6":5,"q7":"More stretch projects would be a plus."}'),
  (@survey_id, '2025-07-25 16:42:30', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-07-28 09:11:54', '{"Q_CHANNEL":"Q3-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"More clarity on priorities."}'),
  (@survey_id, '2025-08-01 13:24:09', '{"Q_CHANNEL":"Q3-2025","q1":5,"q2":4,"q3":4,"q4":2,"q5":4,"q6":5}'),
  (@survey_id, '2025-08-04 15:50:22', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":5,"q3":4,"q4":2,"q5":4,"q6":4,"q7":"I really enjoy the work itself."}'),
  (@survey_id, '2025-08-07 10:37:48', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-08-11 12:05:33', '{"Q_CHANNEL":"Q3-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3}'),
  (@survey_id, '2025-08-14 16:18:15', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":5,"q7":"Cross-team projects are a highlight."}'),
  (@survey_id, '2025-08-18 09:42:50', '{"Q_CHANNEL":"Q3-2025","q1":5,"q2":5,"q3":4,"q4":1,"q5":5,"q6":4}'),
  (@survey_id, '2025-08-21 14:09:27', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":3,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-08-25 11:35:08', '{"Q_CHANNEL":"Q3-2025","q1":3,"q2":4,"q3":4,"q4":2,"q5":3,"q6":4,"q7":"Recognition that gets specific."}'),
  (@survey_id, '2025-08-28 15:22:41', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-09-02 10:14:55', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":5,"q3":5,"q4":1,"q5":5,"q6":4,"q7":"Things are clicking."}'),
  (@survey_id, '2025-09-05 13:48:19', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":5}'),
  (@survey_id, '2025-09-08 16:32:07', '{"Q_CHANNEL":"Q3-2025","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Pretty steady."}'),
  (@survey_id, '2025-09-12 11:18:44', '{"Q_CHANNEL":"Q3-2025","q1":5,"q2":5,"q3":4,"q4":2,"q5":4,"q6":5}'),
  (@survey_id, '2025-09-15 14:55:30', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4,"q7":"Honestly nothing major to change."}'),
  (@survey_id, '2025-09-18 09:24:11', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-09-22 13:41:08', '{"Q_CHANNEL":"Q3-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4,"q7":"Tooling is finally good."}'),
  (@survey_id, '2025-09-25 15:09:55', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-09-28 10:36:42', '{"Q_CHANNEL":"Q3-2025","q1":5,"q2":4,"q3":4,"q4":2,"q5":5,"q6":4,"q7":"The mission resonates."}'),
  (@survey_id, '2025-09-30 14:22:18', '{"Q_CHANNEL":"Q3-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),

-- ===== Wave 2: Q4 2025 (mean target ~3.8) =====
  (@survey_id, '2025-10-05 09:18:33', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4,"q7":"Things are pretty good."}'),
  (@survey_id, '2025-10-09 12:44:21', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":3,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-10-13 15:55:18', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3,"q7":"Reorgs are unsettling."}'),
  (@survey_id, '2025-10-16 10:22:07', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-10-20 14:08:55', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Comp keeping up would help."}'),
  (@survey_id, '2025-10-23 16:35:42', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2025-10-27 11:14:19', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4,"q7":"Mostly fine."}'),
  (@survey_id, '2025-10-30 13:48:33', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":5,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-11-03 09:25:11', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Workload has been heavy."}'),
  (@survey_id, '2025-11-06 15:42:28', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-11-10 10:58:50', '{"Q_CHANNEL":"Q4-2025","q1":5,"q2":4,"q3":4,"q4":2,"q5":4,"q6":5,"q7":"My project is interesting."}'),
  (@survey_id, '2025-11-13 14:31:07', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2025-11-17 11:18:44', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4,"q7":"Less context switching."}'),
  (@survey_id, '2025-11-20 16:05:21', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-11-24 12:33:18', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Hard to say, mixed."}'),
  (@survey_id, '2025-11-27 09:47:55', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-12-01 14:14:42', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4,"q7":"Quieter focus time would help."}'),
  (@survey_id, '2025-12-04 11:38:19', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-12-08 15:25:08', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Year-end stress is real."}'),
  (@survey_id, '2025-12-11 10:51:44', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-12-15 13:19:30', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Pace has been intense."}'),
  (@survey_id, '2025-12-18 16:48:17', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-12-22 09:35:55', '{"Q_CHANNEL":"Q4-2025","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3,"q7":"Holiday burnout starting."}'),
  (@survey_id, '2025-12-26 12:08:22', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2025-12-29 15:42:08', '{"Q_CHANNEL":"Q4-2025","q1":4,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Looking forward to the new year."}'),

-- ===== Wave 3: Q1 2026 (mean target ~3.6) =====
  (@survey_id, '2026-01-08 10:18:55', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"A clearer direction would help."}'),
  (@survey_id, '2026-01-12 13:42:19', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3}'),
  (@survey_id, '2026-01-16 15:55:08', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Compensation. The conversation is overdue."}'),
  (@survey_id, '2026-01-20 09:22:44', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4}'),
  (@survey_id, '2026-01-23 14:08:31', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Reorg fatigue is real."}'),
  (@survey_id, '2026-01-27 16:35:18', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3}'),
  (@survey_id, '2026-01-30 11:48:55', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4,"q7":"Mostly fine, just stretched."}'),
  (@survey_id, '2026-02-03 13:24:42', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-02-06 15:51:19', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"I am tired."}'),
  (@survey_id, '2026-02-10 10:18:08', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4}'),
  (@survey_id, '2026-02-13 14:44:55', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3,"q7":"Workload."}'),
  (@survey_id, '2026-02-17 12:09:33', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":4,"q4":2,"q5":4,"q6":4}'),
  (@survey_id, '2026-02-20 16:38:21', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"More clarity on direction."}'),
  (@survey_id, '2026-02-24 09:55:08', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-02-27 13:31:44', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Recognition is fading."}'),
  (@survey_id, '2026-03-03 15:17:31', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4}'),
  (@survey_id, '2026-03-06 10:48:18', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Things feel heavy."}'),
  (@survey_id, '2026-03-10 14:25:55', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-03-13 11:08:42', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Comp."}'),
  (@survey_id, '2026-03-17 16:35:08', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-03-20 13:18:55', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3,"q7":"Need a clearer career path."}'),
  (@survey_id, '2026-03-24 15:42:31', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-03-27 10:55:08', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":4,"q6":4,"q7":"Things could be better but are okay."}'),
  (@survey_id, '2026-03-30 14:22:44', '{"Q_CHANNEL":"Q1-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-04-01 09:38:19', '{"Q_CHANNEL":"Q1-2026","q1":4,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":"Pace has been hard."}'),

-- ===== Wave 4: Q2 2026 (mean target ~3.4) =====
  (@survey_id, '2026-04-08 11:25:18', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"Honestly I am burned out."}'),
  (@survey_id, '2026-04-12 14:08:55', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-04-15 16:42:31', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Workload."}'),
  (@survey_id, '2026-04-18 10:35:08', '{"Q_CHANNEL":"Q2-2026","q1":4,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-04-22 13:18:44', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Comp gap is growing."}'),
  (@survey_id, '2026-04-25 15:55:19', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-04-29 12:08:08', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":"I am considering leaving."}'),
  (@survey_id, '2026-05-02 14:42:55', '{"Q_CHANNEL":"Q2-2026","q1":4,"q2":3,"q3":3,"q4":3,"q5":4,"q6":3}'),
  (@survey_id, '2026-05-05 09:18:31', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"The role has changed underneath me."}'),
  (@survey_id, '2026-05-08 11:55:08', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-05-10 16:25:44', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Recognition is gone."}'),
  (@survey_id, '2026-05-11 10:08:19', '{"Q_CHANNEL":"Q2-2026","q1":4,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-05-12 13:35:08', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":2,"q4":4,"q5":3,"q6":3,"q7":"Tired most weeks."}'),
  (@survey_id, '2026-05-13 15:48:55', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-05-13 09:22:31', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Career path is unclear."}'),
  (@survey_id, '2026-05-14 11:08:08', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-05-14 13:42:55', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":2,"q4":4,"q5":3,"q6":3,"q7":"Pay gap with market is wide."}'),
  (@survey_id, '2026-05-14 16:18:31', '{"Q_CHANNEL":"Q2-2026","q1":4,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-05-14 18:35:08', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Less reorg churn would matter."}'),
  (@survey_id, '2026-05-14 19:48:44', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-05-14 20:25:19', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Need a real raise."}'),
  (@survey_id, '2026-05-14 21:08:08', '{"Q_CHANNEL":"Q2-2026","q1":4,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4}'),
  (@survey_id, '2026-05-14 22:42:55', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Honestly hanging on."}'),
  (@survey_id, '2026-05-14 23:18:31', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":3}'),
  (@survey_id, '2026-05-14 23:55:08', '{"Q_CHANNEL":"Q2-2026","q1":3,"q2":3,"q3":3,"q4":4,"q5":3,"q6":3,"q7":"Compensation has to move."}');

-- Verify.
SELECT id, slug, title FROM surveys WHERE id = @survey_id;
SELECT JSON_UNQUOTE(JSON_EXTRACT(answers, '$.Q_CHANNEL')) AS wave, COUNT(*) AS responses
  FROM responses WHERE survey_id = @survey_id GROUP BY wave ORDER BY wave;

-- Roll-back (run only if you need to undo):
-- DELETE FROM responses WHERE survey_id = @survey_id;
-- DELETE FROM surveys WHERE id = @survey_id;
