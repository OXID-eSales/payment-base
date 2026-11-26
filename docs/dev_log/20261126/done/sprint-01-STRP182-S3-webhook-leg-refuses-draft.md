# Sprint 01 STRP-182 — S3: the webhook leg refuses a draft

**Status:** DONE 2026-11-26

## What changed

`src/Service/Commit/ContractCommitService.php::refusal()`: the branch for a terminal contract now also takes a
`draft` contract: `if ($state->isTerminal() || $state->isDraft())`. The reason is built from the state as before, so
a draft gives `CommitOutcome::refused('contract_draft')`, and the `error` log is the same one (text changed from
"… that is already closed" to "… that has no open order", because it now covers both cases; no test or consumer
reads that text, checked with `grep`). No event is dispatched. The webhook caller acknowledges a refusal; the PSP
does not retry a delivery that can never succeed.

No new code path: the draft uses the refusal that the terminal case already has (DRY).

## Red → green

| Test | Before | After |
|---|---|---|
| `ContractCommitServiceTest::testCommit_WhenTheContractIsADraft_IsRefusedAndNotDispatched` | red (the draft went into the chain: `DomainException`) | green |
| `ContractCommitServiceTest` (all) | 11 | 12 green, 43 assertions (terminal case still `contract_cancelled`) |
