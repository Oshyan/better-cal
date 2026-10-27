// Run the real invitation handler without a browser or network.
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../src/app/EventSheetBody.js', import.meta.url), 'utf8');
const block = source.slice(source.indexOf('const ANSWERS ='), source.indexOf('\nfunction copyText('));
const html = (strings, ...values) => ({ strings, values });
const walk = (node) => Array.isArray(node) ? node.flatMap(walk) : node?.values ? [node, ...node.values.flatMap(walk)] : [node];
let confirm = false, confirmations = 0;
const sent = [];
const InviteBlock = runInNewContext(block + '\nInviteBlock', {
  html, Icon: () => null, useState: (value) => [value, () => {}],
  askConfirm: async () => { confirmations++; return confirm; },
  rsvpEvent: async (...args) => { sent.push(args); return {}; },
});
const occurrence = { invite: { kind: 'invitation', canReply: true, myPartstat: 'ACCEPTED', reply: { partstat: 'ACCEPTED', sent: false, reason: 'uncertain', message: 'The reply may have been sent.' } } };
const tree = InviteBlock({ occ: occurrence });
const panel = walk(tree).find((node) => node?.strings?.join('').includes('>Retry</button>'));
const retry = panel.values.find((value) => typeof value === 'function');
await retry();
assert.equal(confirmations, 1);
assert.equal(sent.length, 0, 'declining the confirmation cannot send');
confirm = true;
await retry();
assert.equal(sent.length, 1);
assert.equal(sent[0][2], true, 'confirmed retry explicitly authorizes uncertain resend');
assert.ok(!panel.strings.join('').includes("reply couldn't be sent"), 'uncertainty must not claim non-delivery');

const actions = readFileSync(new URL('../src/app/actions.js', import.meta.url), 'utf8');
const outcomeSource = actions.slice(actions.indexOf('export function rsvpOutcome'), actions.indexOf('// Answer an invitation (#70)')).replace('export ', '');
const outcome = runInNewContext(outcomeSource + '\nrsvpOutcome');
assert.equal(outcome({ reason: 'uncertain', message: 'May have been sent.' }, 'mail').text, 'May have been sent.');
console.log('Mail reply confirmation regression tests passed');

const reviewSource = readFileSync(new URL('../src/app/ReviewPage.js', import.meta.url), 'utf8');
const reviewBlock = reviewSource.slice(reviewSource.indexOf('const KIND_LABEL ='), reviewSource.indexOf('export function ReviewPage'));
const ReviewCard = runInNewContext(reviewBlock + '\nReviewCard', {
  html, Icon: () => null, useState: (value) => [value, () => {}],
  usePasswordStepUp: () => ({ prompt: null }),
});
for (const [isInvitation, label] of [[true, 'New emailed invitation'], [false, 'New emailed event']]) {
  const card = ReviewCard({ item: { kind: 'invite_new', status: 'open', detail: { isInvitation }, actions: [] } });
  const badge = walk(card).find((node) => node?.strings?.some((part) => part.includes('class="bc-review-kind" title=')));
  const titleIndex = badge.strings.findIndex((part) => part.includes('class="bc-review-kind" title='));
  assert.equal(badge.values[titleIndex], label);
}
console.log('Mail Review label regression tests passed');
