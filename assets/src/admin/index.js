import '../../css/admin.css';
import { initUserAssignments } from './user-assignments.js';
import { initCustomerGroups } from './customer-groups.js';
import { initPricingRulesPage } from './pricing-rules-page.js';
import { initPricingRuleForm } from './pricing-rule-form.js';
import { initPricingRuleSort } from './pricing-rule-sort.js';
import { initPricingRuleSchedule } from './pricing-rule-schedule.js';
import { initPricingRuleModal } from './pricing-rule-modal.js';

jQuery(function($) {
    initUserAssignments($);
    initCustomerGroups($);
    initPricingRulesPage($);
    initPricingRuleForm($);
    initPricingRuleSort($);
    initPricingRuleSchedule($);
    initPricingRuleModal($);
});
