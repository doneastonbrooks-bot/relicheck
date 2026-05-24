-- ReliCheck demo survey loader
-- Run this once in phpMyAdmin against the dbs15641829 database.
-- Inserts a survey owned by don.eastonbrooks@gmail.com plus 50 attached responses.
-- Re-running creates a second copy. Delete via the My Surveys page if needed.

USE dbs15641829;

SET @owner_id := (SELECT id FROM users WHERE email = 'don.eastonbrooks@gmail.com' LIMIT 1);
SET @demo_slug := 'demo-engagement-2026';

-- If the slug is taken, append a short suffix so the insert succeeds.
SET @demo_slug := IF(
  EXISTS(SELECT 1 FROM surveys WHERE slug = @demo_slug),
  CONCAT(@demo_slug, '-', SUBSTRING(MD5(RAND()), 1, 4)),
  @demo_slug
);

-- 1. Insert the survey.
INSERT INTO surveys (owner_id, slug, title, description, settings, questions, is_published)
VALUES (
  @owner_id,
  @demo_slug,
  'Hybrid Work Engagement (Demo)',
  'Demo survey with 50 sample responses, designed to exercise every AI tool on the platform.',
  '{"likertPoints":5,"likertLow":"Strongly disagree","likertHigh":"Strongly agree","thankYou":"Thanks for sharing your thoughts. Results will be reviewed by your team lead."}',
  '[{"id":"q1","type":"likert","prompt":"I feel motivated by my work.","required":true,"reverse":false},{"id":"q2","type":"likert","prompt":"I find my work meaningful and worthwhile.","required":true,"reverse":false},{"id":"q3","type":"likert","prompt":"I feel disconnected from my teammates.","required":true,"reverse":true},{"id":"q4","type":"likert","prompt":"I receive useful feedback from my manager.","required":true,"reverse":false},{"id":"q5","type":"likert","prompt":"My workload is unmanageable most days.","required":true,"reverse":true},{"id":"q6","type":"likert","prompt":"I have the tools I need to do my job well.","required":true,"reverse":false},{"id":"q7","type":"likert","prompt":"I am recognized when I do good work.","required":true,"reverse":false},{"id":"q8","type":"likert","prompt":"I prefer working in the morning over the afternoon.","required":false,"reverse":false},{"id":"q9","type":"single","prompt":"How long have you been in your current role?","required":false,"options":["Less than 1 year","1 to 3 years","3 to 5 years","More than 5 years"]},{"id":"q10","type":"multi","prompt":"Which of the following describe your typical workday? (Pick any that apply.)","required":false,"options":["Mostly meetings","Mostly individual work","Mostly client-facing","A mix of all of the above"]},{"id":"q11","type":"open","prompt":"What''s working well for you in our hybrid setup?","required":false},{"id":"q12","type":"open","prompt":"What would you change to improve your experience?","required":false}]',
  1
);

SET @survey_id := LAST_INSERT_ID();

-- 2. Insert 50 responses.
INSERT INTO responses (survey_id, submitted_at, answers) VALUES
  (@survey_id, '2026-04-22 16:17:20', '{"q1":4,"q2":3,"q3":2,"q4":4,"q5":2,"q6":4,"q7":3,"q8":3,"q9":2,"q10":[1,3],"q12":"Make the in-office days actually meaningful instead of being on Zoom from the office."}'),
  (@survey_id, '2026-04-22 23:31:28', '{"q1":3,"q2":4,"q3":3,"q4":3,"q5":2,"q6":2,"q7":3,"q8":3,"q9":3,"q10":[3],"q11":"Our team standups are short and useful.","q12":"The hiring process is way too slow, we lose great candidates."}'),
  (@survey_id, '2026-04-23 05:43:30', '{"q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":4,"q7":3,"q8":1,"q9":3,"q10":[0,1],"q11":"Our project management tooling finally feels like it works for us.","q12":"Honestly I am not sure."}'),
  (@survey_id, '2026-04-23 10:00:52', '{"q1":3,"q2":4,"q3":4,"q4":3,"q5":3,"q6":3,"q7":4,"q8":2,"q9":2,"q10":[1,2,3],"q11":"The office is closer to my house than my last job, which is convenient.","q12":"Keep doing the company-wide Q and A sessions, they help a lot."}'),
  (@survey_id, '2026-04-23 22:32:49', '{"q1":5,"q2":4,"q3":1,"q4":4,"q5":2,"q6":4,"q7":5,"q8":2,"q9":3,"q10":[0],"q12":"I want to know what success looks like for my role."}'),
  (@survey_id, '2026-04-24 04:07:57', '{"q1":4,"q2":3,"q3":1,"q4":4,"q5":2,"q6":4,"q7":4,"q8":3,"q9":2,"q10":[1],"q11":"Office days are great for connection but the commute eats my morning.","q12":"Pay raises that keep up with inflation would be nice."}'),
  (@survey_id, '2026-04-24 12:13:03', '{"q1":3,"q2":2,"q3":3,"q4":3,"q5":3,"q6":4,"q7":3,"q8":2,"q9":0,"q10":[0,1,3],"q11":"I learned a lot from my last project and got recognized for it.","q12":"Workload is genuinely unsustainable. I work most weekends."}'),
  (@survey_id, '2026-04-24 21:18:56', '{"q1":4,"q2":3,"q3":1,"q4":3,"q5":2,"q6":4,"q7":4,"q8":2,"q9":0,"q10":[3],"q11":"I work two days in the office and three at home, like most of my team.","q12":"Designated team days so we are actually in the office together."}'),
  (@survey_id, '2026-04-24 23:32:50', '{"q1":3,"q2":2,"q3":3,"q4":2,"q5":5,"q6":3,"q7":3,"q8":2,"q9":1,"q10":[0,1,3],"q11":"The new laptops and dual monitors at home make a big difference."}'),
  (@survey_id, '2026-04-25 03:31:28', '{"q1":4,"q2":5,"q3":1,"q4":5,"q5":1,"q6":5,"q7":5,"q8":2,"q9":3,"q10":[0,1],"q11":"The new collaboration tools are powerful but the learning curve was rough.","q12":"I would change the on-call rotation, it is brutal."}'),
  (@survey_id, '2026-04-25 11:41:52', '{"q1":4,"q2":4,"q3":3,"q4":3,"q5":1,"q6":5,"q7":4,"q8":3,"q9":1,"q10":[0],"q11":"Hard to think of anything specific.","q12":"I would have to think about it more."}'),
  (@survey_id, '2026-04-25 13:57:41', '{"q1":4,"q2":3,"q3":2,"q4":3,"q5":3,"q6":3,"q7":4,"q8":4,"q9":1,"q10":[3],"q11":"Training budget actually got used this year.","q12":"More cross-team social events would be nice."}'),
  (@survey_id, '2026-04-25 17:18:49', '{"q1":3,"q2":3,"q3":2,"q4":3,"q5":3,"q6":3,"q7":3,"q8":1,"q9":3,"q10":[0,3],"q11":"Leadership has been transparent about company strategy this year.","q12":"Stop the constant context switching between three projects at once."}'),
  (@survey_id, '2026-04-25 22:37:55', '{"q1":4,"q2":4,"q3":2,"q4":4,"q5":2,"q6":4,"q7":3,"q8":3,"q9":3,"q10":[1],"q12":"Recognize the people doing the actual work, not just the people who self-promote."}'),
  (@survey_id, '2026-04-26 00:55:02', '{"q1":4,"q2":5,"q3":3,"q4":4,"q5":2,"q6":4,"q7":4,"q8":2,"q9":1,"q10":[0,2],"q11":"I see how my work connects to the company mission.","q12":"Set clearer expectations from my manager."}'),
  (@survey_id, '2026-04-26 02:08:04', '{"q1":2,"q2":2,"q3":4,"q4":2,"q5":5,"q6":2,"q7":2,"q8":3,"q9":3,"q10":[1,2,3],"q11":"My manager is supportive and trusts us to do our jobs.","q12":"Mostly happy but the new product manager process is painful."}'),
  (@survey_id, '2026-04-27 01:23:19', '{"q1":4,"q2":5,"q3":2,"q4":5,"q5":2,"q6":4,"q7":3,"q8":2,"q9":3,"q10":[3],"q11":"Pay is good but workload is unsustainable.","q12":"Nothing major to change at this point."}'),
  (@survey_id, '2026-04-27 06:16:15', '{"q1":5,"q2":5,"q3":1,"q4":5,"q5":2,"q6":4,"q7":4,"q8":1,"q9":1,"q10":[0,3],"q11":"The flexibility to set my own hours has been life-changing for my family.","q12":"Better manager training. My manager has never managed before and it shows."}'),
  (@survey_id, '2026-04-27 06:50:45', '{"q1":4,"q2":5,"q3":3,"q4":4,"q5":1,"q6":4,"q7":4,"q8":5,"q9":1,"q10":[3],"q11":"Asynchronous communication respects my deep-work time.","q12":"Better onboarding for new hires, my buddy was overwhelmed."}'),
  (@survey_id, '2026-04-27 07:26:01', '{"q1":4,"q2":5,"q3":2,"q4":4,"q5":2,"q6":4,"q7":4,"q8":2,"q9":3,"q10":[0,2,3],"q11":"I love remote work but I miss the spontaneous hallway chats.","q12":"Reduce the number of meetings, half of them could be emails."}'),
  (@survey_id, '2026-04-29 17:16:45', '{"q1":3,"q2":5,"q3":2,"q4":4,"q5":2,"q6":5,"q7":3,"q8":2,"q9":0,"q10":[1,3],"q11":"Honestly not much is working well right now.","q12":"Leadership talks about culture but does not act on the survey results."}'),
  (@survey_id, '2026-04-29 17:45:57', '{"q1":3,"q2":3,"q3":4,"q4":3,"q5":3,"q6":3,"q7":2,"q8":4,"q9":1,"q10":[0,3],"q11":"Hybrid lets me balance focused work at home with collaboration in the office.","q12":"Better office snacks and coffee, ours are bad."}'),
  (@survey_id, '2026-04-30 05:29:10', '{"q1":2,"q2":3,"q3":4,"q4":2,"q5":4,"q6":1,"q7":1,"q8":5,"q9":1,"q10":[2,3],"q12":"I love the work but the workload needs to come down."}'),
  (@survey_id, '2026-04-30 08:35:12', '{"q1":5,"q2":4,"q3":1,"q4":4,"q5":2,"q6":4,"q7":4,"q8":3,"q9":2,"q10":[1],"q11":"My manager and I do regular career conversations and they are valuable."}'),
  (@survey_id, '2026-05-01 07:05:03', '{"q1":3,"q2":3,"q3":2,"q4":2,"q5":3,"q6":2,"q7":2,"q8":1,"q9":3,"q10":[3],"q12":"Less micromanagement, more trust."}'),
  (@survey_id, '2026-05-01 16:37:57', '{"q1":2,"q2":2,"q3":4,"q4":3,"q5":4,"q6":2,"q7":2,"q8":2,"q9":0,"q10":[0,1],"q11":"Being able to handle a doctor''s appointment without taking PTO is huge.","q12":"Performance reviews need to be more rigorous and less political."}'),
  (@survey_id, '2026-05-01 17:16:33', '{"q1":2,"q2":3,"q3":3,"q4":2,"q5":3,"q6":3,"q7":3,"q8":4,"q9":2,"q10":[3],"q11":"Being able to skip a long commute on my deep-work days is amazing.","q12":"Stop adding scope mid-project without removing anything."}'),
  (@survey_id, '2026-05-02 03:18:48', '{"q1":4,"q2":2,"q3":4,"q4":3,"q5":4,"q6":3,"q7":3,"q8":4,"q9":1,"q10":[0,1],"q11":"Things are fine for me, no major complaints.","q12":"Hire more people on my team. We have been short-staffed for a year."}'),
  (@survey_id, '2026-05-02 06:15:06', '{"q1":5,"q2":5,"q3":2,"q4":4,"q5":2,"q6":4,"q7":4,"q8":3,"q9":1,"q10":[0,1,3],"q11":"I can pick my kids up from school and log back on later.","q12":"More transparency around compensation and progression."}'),
  (@survey_id, '2026-05-02 11:07:06', '{"q1":3,"q2":4,"q3":2,"q4":4,"q5":2,"q6":3,"q7":3,"q8":1,"q9":2,"q10":[2,3],"q11":"Slack culture is healthy and asynchronous when it needs to be.","q12":"More learning budget would be great, otherwise things are good."}'),
  (@survey_id, '2026-05-02 14:43:27', '{"q1":2,"q2":3,"q3":2,"q4":3,"q5":3,"q6":2,"q7":2,"q8":5,"q9":3,"q10":[0,1],"q12":"Better communication from senior leadership about strategy."}'),
  (@survey_id, '2026-05-02 15:33:59', '{"q1":4,"q2":4,"q3":2,"q4":4,"q5":1,"q6":5,"q7":5,"q8":2,"q9":0,"q10":[2],"q11":"I love being able to work from home a few days a week.","q12":"Stop making promises about promotions and then not following through."}'),
  (@survey_id, '2026-05-02 17:33:55', '{"q1":4,"q2":4,"q3":2,"q4":4,"q5":1,"q6":4,"q7":4,"q8":4,"q9":2,"q10":[2],"q11":"I have great coworkers who look out for each other.","q12":"Stop forcing in-office days when most of my team is remote."}'),
  (@survey_id, '2026-05-03 06:51:48', '{"q1":5,"q2":4,"q3":3,"q4":5,"q5":2,"q6":4,"q7":4,"q8":4,"q9":1,"q10":[1,3],"q11":"The flexibility to set my own hours has been life-changing for my family.","q12":"More public acknowledgment of team wins."}'),
  (@survey_id, '2026-05-03 09:30:52', '{"q1":2,"q2":3,"q3":3,"q4":2,"q5":3,"q6":2,"q7":3,"q8":3,"q9":0,"q10":[0,1],"q11":"I love being able to work from home a few days a week.","q12":"Continue investing in hybrid tools and AV equipment in conference rooms."}'),
  (@survey_id, '2026-05-03 11:28:37', '{"q1":1,"q2":4,"q3":4,"q4":2,"q5":4,"q6":3,"q7":3,"q8":5,"q9":1,"q10":[3],"q11":"Hybrid lets me balance focused work at home with collaboration in the office.","q12":"Workload is genuinely unsustainable. I work most weekends."}'),
  (@survey_id, '2026-05-03 19:05:44', '{"q1":2,"q2":1,"q3":5,"q4":2,"q5":4,"q6":2,"q7":3,"q8":3,"q9":0,"q10":[0,1],"q11":"Flexible scheduling lets me actually exercise during the day.","q12":"Reduce the number of meetings, half of them could be emails."}'),
  (@survey_id, '2026-05-04 01:52:05', '{"q1":3,"q2":3,"q3":4,"q4":3,"q5":3,"q6":3,"q7":3,"q8":3,"q9":0,"q10":[3],"q11":"Being able to skip a long commute on my deep-work days is amazing."}'),
  (@survey_id, '2026-05-04 04:44:23', '{"q1":4,"q2":3,"q3":2,"q4":3,"q5":2,"q6":4,"q7":3,"q8":4,"q9":2,"q10":[2],"q11":"Our team standups are short and useful.","q12":"Hire more people on my team. We have been short-staffed for a year."}'),
  (@survey_id, '2026-05-04 06:25:25', '{"q1":3,"q2":3,"q3":3,"q4":3,"q5":3,"q6":3,"q7":2,"q8":4,"q9":1,"q10":[0,1],"q11":"My manager is supportive and trusts us to do our jobs.","q12":"Stop the constant context switching between three projects at once."}'),
  (@survey_id, '2026-05-04 17:30:11', '{"q1":4,"q2":2,"q3":3,"q4":3,"q5":2,"q6":3,"q7":4,"q8":3,"q9":0,"q10":[3],"q11":"I have great coworkers who look out for each other.","q12":"I would change the on-call rotation, it is brutal."}'),
  (@survey_id, '2026-05-05 04:55:56', '{"q1":3,"q2":3,"q3":3,"q4":4,"q5":2,"q6":4,"q7":3,"q8":3,"q9":1,"q10":[0]}'),
  (@survey_id, '2026-05-05 06:37:16', '{"q1":3,"q2":4,"q3":3,"q4":2,"q5":3,"q6":3,"q7":3,"q8":2,"q9":1,"q10":[0,1],"q12":"I want to know what success looks like for my role."}'),
  (@survey_id, '2026-05-05 06:46:45', '{"q1":3,"q2":2,"q3":2,"q4":2,"q5":4,"q6":3,"q7":3,"q8":4,"q9":3,"q10":[1],"q11":"The new laptops and dual monitors at home make a big difference.","q12":"Leadership talks about culture but does not act on the survey results."}'),
  (@survey_id, '2026-05-05 08:16:21', '{"q1":4,"q2":4,"q3":3,"q4":4,"q5":3,"q6":3,"q7":3,"q8":1,"q9":0,"q10":[3],"q11":"Our project management tooling finally feels like it works for us.","q12":"Stop making promises about promotions and then not following through."}'),
  (@survey_id, '2026-05-05 12:29:17', '{"q1":5,"q2":5,"q3":2,"q4":4,"q5":1,"q6":5,"q7":3,"q8":3,"q9":1,"q10":[0,3],"q11":"Office days are great for connection but the commute eats my morning.","q12":"More transparency around compensation and progression."}'),
  (@survey_id, '2026-05-05 13:09:18', '{"q1":4,"q2":5,"q3":2,"q4":4,"q5":1,"q6":3,"q7":5,"q8":1,"q9":1,"q10":[0],"q11":"I love remote work but I miss the spontaneous hallway chats.","q12":"Our tooling is fragmented, we use four different systems for the same data."}'),
  (@survey_id, '2026-05-06 00:55:01', '{"q1":3,"q2":4,"q3":3,"q4":3,"q5":3,"q6":4,"q7":3,"q8":2,"q9":1,"q10":[1],"q11":"The new collaboration tools are powerful but the learning curve was rough.","q12":"Performance reviews need to be more rigorous and less political."}'),
  (@survey_id, '2026-05-06 05:28:49', '{"q1":2,"q2":3,"q3":3,"q4":3,"q5":3,"q6":2,"q7":2,"q8":4,"q9":1,"q10":[1],"q11":"I work two days in the office and three at home, like most of my team.","q12":"The hiring process is way too slow, we lose great candidates."}'),
  (@survey_id, '2026-05-06 10:54:29', '{"q1":3,"q2":4,"q3":3,"q4":3,"q5":4,"q6":3,"q7":1,"q8":2,"q9":2,"q10":[1],"q11":"The office is closer to my house than my last job, which is convenient.","q12":"Better onboarding for new hires, my buddy was overwhelmed."}');

-- 3. Show the new survey id and response count for confirmation.
SELECT @survey_id AS new_survey_id, @demo_slug AS slug,
  (SELECT COUNT(*) FROM responses WHERE survey_id = @survey_id) AS response_count;