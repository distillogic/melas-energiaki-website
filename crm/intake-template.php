<?php
declare(strict_types=1);

function intake_document_html(array $data): string
{
    $e = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = static function (string $key, string $fallback = 'Not yet completed') use ($data, $e): string {
        $text = trim((string)($data[$key] ?? ''));
        return nl2br($e($text !== '' ? $text : $fallback));
    };
    $section = static function (string $number, string $title, array $rows) use ($value): string {
        $html = '<section><h2>' . htmlspecialchars($number . '. ' . $title, ENT_QUOTES, 'UTF-8') . '</h2><dl>';
        foreach ($rows as $label => $key) $html .= '<div><dt>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</dt><dd>' . $value($key) . '</dd></div>';
        return $html . '</dl></section>';
    };
    $logo = (string)($data['logo_url'] ?? '');
    $html = '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($data['document_reference'] ?? 'DL-INT') . '</title><style>
    @page{size:A4;margin:18mm 17mm 18mm}*{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#142238;margin:0;font-size:10.5pt;line-height:1.45}.cover{min-height:250mm;display:flex;flex-direction:column}.logo{width:190px;max-height:72px;object-fit:contain;object-position:left center}.eyebrow{color:#176afc;font-weight:800;letter-spacing:.14em;margin-top:42mm}.cover h1{font-size:32pt;line-height:1.04;color:#071a33;margin:8px 0 12px}.subtitle{font-size:15pt;color:#176afc;font-weight:700}.meta{margin-top:24mm;color:#5a6b82}.notice{margin-top:18mm;border:1px solid #c5d4e8;background:#f3f7fd;padding:14px;color:#45566d}section{break-inside:avoid;margin:0 0 15px}h2{font-size:12pt;color:#fff;background:#071a33;padding:8px 10px;margin:0 0 8px}dl{margin:0;display:grid;grid-template-columns:1fr 1fr;gap:7px 10px}dl div{border:1px solid #c5d4e8;background:#f7faff;padding:8px;min-height:48px;break-inside:avoid}dt{font-weight:700;color:#071a33;font-size:8.5pt;text-transform:uppercase;letter-spacing:.03em}dd{margin:4px 0 0;white-space:normal}.full{grid-column:1/-1}.approval{break-before:page}.signature{height:70px;border-bottom:1px solid #071a33;margin-top:12px}.footer{margin-top:24px;border-top:1px solid #c5d4e8;padding-top:8px;color:#5a6b82;font-size:8pt}@media print{.cover{page-break-after:always}}
    </style></head><body><div class="cover">';
    $html .= '<style>section{break-inside:auto!important;page-break-inside:auto!important;margin-bottom:13px}h2{font-size:10.8pt;break-after:avoid;page-break-after:avoid}dl{gap:8px 10px;align-items:start}dl div{min-height:0;min-width:0;break-inside:avoid;page-break-inside:avoid;overflow-wrap:anywhere}.signature{height:64px;margin-top:10px}.footer{margin-top:16px}</style>';
    if ($logo !== '') $html .= '<img class="logo" src="' . $e($logo) . '" alt="DISTILLOGIC">';
    $html .= '<p class="eyebrow">INTERNAL / COMMERCIAL CONFIDENTIAL</p><h1>PROJECT INTAKE &amp;<br>RFP QUALIFICATION FORM</h1><p class="subtitle">Software Engineering &amp; Technology Delivery</p><p class="meta"><strong>Reference:</strong> ' . $e($data['document_reference'] ?? '') . '<br><strong>Version:</strong> ' . $e($data['version'] ?? '1.0') . '<br><strong>Date:</strong> ' . $e($data['date_display'] ?? '') . '<br><strong>Client:</strong> ' . $e($data['client_legal_name'] ?? '') . '</p><p class="notice">Internal use only. Complete this qualification before issuing a proposal. Do not send this document to the client.</p></div>';
    $html .= $section('1-3', 'Opportunity & Decision Structure', [
        'Client / Organisation'=>'client_legal_name','Country'=>'country','Website'=>'website','Opportunity / Project'=>'project_name','Internal Opportunity Reference'=>'opportunity_reference','Date Received'=>'date_display','Source'=>'source','Primary Contact'=>'primary_contact','Position / Department'=>'contact_position','Email / Telephone'=>'contact_details','Role in Decision'=>'decision_role','Decision-Making Structure'=>'decision_structure',
    ]);
    $html .= $section('4-9', 'Requirement, Scope & Acceptance', [
        'Client Requirement'=>'requirement','Problem to Solve'=>'problem','Business / Technical Driver'=>'driver','Expected Outcome'=>'expected_outcome','Engagement Type'=>'engagement_type','Client Delivery Preference'=>'client_delivery_model','Recommended Model & Reason'=>'recommended_model','In Scope'=>'in_scope','Out of Scope'=>'out_of_scope','Scope Clarity / Notes'=>'scope_clarity','Deliverables'=>'deliverables','Acceptance Criteria / Approver / Review Period'=>'acceptance',
    ]);
    $html .= $section('10-17', 'Technology, Resources & Timeline', [
        'Current Technical Environment'=>'technical_environment','Architecture Status & Documentation'=>'architecture_status','Legacy / Existing System'=>'legacy_system','Resource Requirements'=>'resource_requirements','Delivery Location / Travel'=>'delivery_location','Working Hours / Time Zone'=>'working_hours','Timeline / Deadline Type'=>'timeline','Urgency & Reason'=>'urgency',
    ]);
    $html .= $section('18-24', 'Commercial & Procurement Qualification', [
        'Budget & Realism'=>'budget','Commercial Expectations'=>'commercial_expectations','Payment Terms / Billing / Risk'=>'payment_terms','Purchase Order / Procurement'=>'procurement','Contractual Requirements'=>'contractual_requirements','Liability Requirements'=>'liability','Insurance Requirements'=>'insurance',
    ]);
    $html .= $section('25-30', 'Security, Data & Dependencies', [
        'Information Security Requirements'=>'security_requirements','Certification Requirements'=>'certification_requirements','Personal Data / GDPR'=>'gdpr','Data Location'=>'data_location','Third-Party Dependencies'=>'third_party_dependencies','Client Dependencies'=>'client_dependencies',
    ]);
    $html .= $section('31-35', 'Opportunity & Risk Assessment', [
        'Competitive Situation'=>'competitive_situation','Client Motivation'=>'client_motivation','Strategic Value'=>'strategic_value','Delivery Risk Assessment'=>'risk_assessment','Red Flags'=>'red_flags',
    ]);
    $html .= $section('36-43', 'Financial Exposure & Internal Readiness', [
        'Commercial Exposure'=>'commercial_exposure','Indicative Opportunity Value'=>'opportunity_value','Win Probability'=>'win_probability','Bid / No-Bid Recommendation'=>'bid_decision','Conditions Before Proposal'=>'conditions_before_proposal','Internal Pricing Review'=>'pricing_review','Proposed Team'=>'proposed_team','Capability Check'=>'capability_check',
    ]);
    $html .= $section('44-46', 'Next Action & Qualification Summary', [
        'Required Next Action'=>'required_next_action','Owner / Action / Target Date'=>'next_action_owner','Client Need'=>'summary_client_need','Proposed DISTILLOGIC Solution'=>'summary_solution','Commercial Opportunity'=>'summary_opportunity','Key Risk'=>'summary_risk','Recommended Next Step'=>'summary_next_step',
    ]);
    $html .= '<section class="approval"><h2>MANAGEMENT APPROVAL</h2><dl><div><dt>Commercial Decision</dt><dd>' . $value('management_decision') . '</dd></div><div><dt>Approved By</dt><dd>Spiros Melas<br>Managing Director</dd></div><div class="full"><dt>Comments / Conditions</dt><dd>' . $value('management_comments') . '</dd></div></dl><p><strong>Signature</strong></p><div class="signature"></div><p><strong>Date</strong></p><div class="signature"></div></section>';
    return $html . '<div class="footer">DISTILLOGIC TECHNOLOGIES - Project Intake &amp; RFP Qualification - ' . $e($data['document_reference'] ?? '') . '</div></body></html>';
}
