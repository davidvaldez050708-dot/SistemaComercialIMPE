'use strict';
const {spawnSync}=require('node:child_process');
const path=require('node:path');
const tests=['test_result_controller.cjs','test_technical_recovery.cjs','test_followup_submit.cjs','test_informative_submit.cjs','test_successor_journal.cjs','test_approved_submission.cjs','test_common_capture.cjs','test_capture_alias_merge.cjs','test_draft_sync.cjs',
 'test_shell_draft_pulse.cjs','test_analyst_draft_restore.cjs','test_reception_pending.cjs',
 'test_sales_pending.cjs','test_hangup_isolation.cjs','test_dial_isolation.cjs','test_host_outgoing_journal.cjs',
 'test_attention_observer.cjs','test_sales_capture_identity.cjs',
 'test_host_incoming_journal.cjs','test_analyst_journal_reader.cjs','test_transfer_journal_link.cjs'];
let failures=0;
for(const name of tests){
 const result=spawnSync(process.execPath,[path.join(__dirname,'tests',name)],{stdio:'inherit',timeout:15000});
 if(result.error||result.status!==0){failures++;console.error('FAILED '+name+(result.error?': '+result.error.message:''));}
}
console.log(`${tests.length-failures}/${tests.length} isolated unit suites passed. No SDK, SQL or provider calls. These are not complete-call acceptance tests.`);
if(failures)process.exitCode=1;
