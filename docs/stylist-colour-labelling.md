# Labelling colour profiles

This is how the colour engine gets calibrated. The engine measures a face and
computes a season; you say what the season actually is; the difference between
the two is what tells us where its reference points are wrong.

Right now they are wrong in at least one known way. The engine reads fair skin
with any warmth in it as maximally cool, because a number in its data file says
that skin at hue 35° is as cool as skin gets — and nobody has ever checked that
against a real face. Your labels are the check.

## The one rule that matters

**Judge the face. Do not judge the engine.**

The labelling screen hides the engine's answer until you have saved yours. If
you read "deep winter" before deciding, you will agree with it more often than
you otherwise would — not because you are careless, but because that is what
anchoring does to everybody. And the agreement rate is the single number this
whole exercise exists to measure. If it is inflated, the report says the engine
is doing better than it is, and the reference points get left where they are.

You can reveal the engine's answer before saving, behind a click. Do that only
when you have already decided and want to see whether it agrees — never as a
way of making up your mind.

## What to fill in

### Season — required

One of the twelve. What you would tell this person they are.

### How sure — required

- **high** — you would say this to a client without hedging.
- **medium** — you are fairly confident, but you can see the argument for
  another season.
- **low** — you are genuinely unsure, or the photo is not good enough to be
  sure from.

Be honest with this one. A report that cannot tell a confident verdict from a
guess weighs them the same, and then a handful of guesses move a reference point
that a hundred confident verdicts should have decided.

### Second season — optional, but please use it

Whenever your confidence is not **high**, name the other season you were
weighing. A stylist hedging is information, not indecision: "true autumn, or
possibly soft autumn" is a far more useful row than "true autumn, medium" on its
own, because it says *which way* the uncertainty runs.

### The three axes — optional, one at a time

These are what the engine's reference points are actually set from, so they
matter even more than the season.

- **Undertone** — warm, cool or neutral. Is the skin golden, rosy, or neither?
- **Depth** — light, medium or deep. Overall, how dark is this person's
  colouring, taking hair and eyes together with the skin?
- **Chroma** — bright or soft. Are their own colours clear and saturated, or
  muted and blended?

**Leave one blank rather than guess.** A blank means "I did not judge this",
which the report handles properly — it simply counts that row out of that axis.
A guess becomes a number in a distribution, and that distribution is what
somebody later uses to set a threshold. One invented answer is worse than fifty
missing ones.

It is completely normal to be sure about the undertone and unsure about the
season. Say exactly that: undertone `warm`, season with confidence `low`, and a
second season.

### Notes — optional

Anything the boxes cannot hold. Things worth writing down:

- the photo is misleading (bad light, heavy make-up, a filter, dyed hair that
  reads as natural);
- the person is between two seasons in a way the second-season box does not
  capture;
- you know this person and the photo does not look like them.

## When the photo has gone

Photos are deleted after 60 hours; the measurements stay forever. If you open a
profile whose photo has been purged, you can still label it from what you know
of the person — or skip it. Either is fine. Do not guess from the numbers: they
are the thing being tested, and a label derived from them tests nothing.

## Several photos of one person

A profile may carry a handle like `p001` and a lighting (`daylight`,
`indoor_warm`, `flash`…). Those are shots of the same person under different
light, gathered so that the white-balance correction can be measured.

Label each one **on its own**, as you see it. If the flash photo looks like a
different season to you than the daylight one, that is exactly the finding — do
not reconcile them yourself.

## Disagreeing with another stylist

If somebody else has already labelled a profile, you do not see their verdict
and you cannot change it. Your label is stored beside theirs. Two stylists
disagreeing about one face is kept on purpose: it is the honest ceiling on how
well any automatic classifier could ever do, and pretending to a single truth
would hide it.

## What happens to your labels

They are stored with the profile and kept for as long as it is. They go into an
export that contains measurements and verdicts and nothing else — no names, no
phone numbers, no photos, no filenames — which is then read by whoever adjusts
the engine's reference points.

Nothing is automatic. No command in this system changes a threshold on the
strength of your labels. A person reads the report and decides.
