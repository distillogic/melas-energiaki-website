<?php
declare(strict_types=1);

function msa_document_html(array $data): string
{
    $source = __DIR__ . '/assets/documents/DISTILLOGIC-MSA-Master-Template-v1.0.txt';
    $text = is_file($source) ? file_get_contents($source) : false;
    if (!is_string($text) || $text === '') {
        throw new RuntimeException('Το master MSA template δεν βρέθηκε.');
    }

    $start = strpos($text, "DISTILLOGIC TECHNOLOGIES\nMASTER SERVICES AGREEMENT");
    if ($start !== false) $text = substr($text, $start);
    $values = [
        '[FULL LEGAL COMPANY NAME]' => 'S. D. MELAS TRADING BUSINESS',
        '[FULL LEGAL NAME]' => 'S. D. MELAS TRADING BUSINESS',
        '[VAT NUMBER]' => 'EL132234268',
        '[GEMI NUMBER]' => '147185216000',
        '[LEGAL ENTITY]' => 'S. D. MELAS TRADING BUSINESS',
        '[EFFECTIVE DATE]' => (string)($data['effective_date_display'] ?? '[DATE]'),
        '[DATE]' => (string)($data['effective_date_display'] ?? '[DATE]'),
        'DL-MSA-[NUMBER]' => (string)($data['msa_reference'] ?? 'DL-MSA-[NUMBER]'),
        '[CLIENT FULL LEGAL NAME]' => (string)($data['client_legal_name'] ?? '[CLIENT FULL LEGAL NAME]'),
        '[CLIENT LEGAL NAME]' => (string)($data['client_legal_name'] ?? '[CLIENT LEGAL NAME]'),
        '[CLIENT NAME]' => (string)($data['client_legal_name'] ?? '[CLIENT NAME]'),
        '[COUNTRY]' => (string)($data['client_country'] ?? '[COUNTRY]'),
        '[NUMBER]' => (string)($data['client_registration'] ?? '[NUMBER]'),
        '[INITIAL TERM — e.g. 24 MONTHS]' => (string)($data['initial_term'] ?? '24 months'),
        '[30 / 60 / 90]' => (string)($data['termination_notice_days'] ?? '60'),
        '[12 months]' => (string)($data['non_solicitation_period'] ?? '12 months'),
        '[100% OF FEES PAID OR PAYABLE UNDER THE AFFECTED SOW DURING THE PRECEDING 12 MONTHS]' => (string)($data['liability_cap'] ?? '[LIABILITY CAP - LEGAL REVIEW REQUIRED]'),
        '[AUTHORISED DISTILLOGIC EMAIL]' => (string)($data['distillogic_signer_email'] ?? 'melas@distillogic.gr'),
        '[LEGAL / COMMERCIAL EMAIL]' => (string)($data['distillogic_signer_email'] ?? 'melas@distillogic.gr'),
        '[TELEPHONE]' => (string)($data['telephone'] ?? '[TELEPHONE]'),
    ];
    $text = strtr($text, $values);
    $text = str_replace('having its registered office at [ADDRESS], Patras, Greece', 'having its registered office at Zoodoxou Pigis 11, Patras, Greece', $text);
    $text = str_replace("DISTILLOGIC\nLegal Entity: S. D. MELAS TRADING BUSINESS\nAttention: Spiros Melas\nTitle: Managing Director\nAddress: [ADDRESS]", "DISTILLOGIC\nLegal Entity: S. D. MELAS TRADING BUSINESS\nAttention: ".($data['distillogic_signer_name'] ?? 'Spiros Melas')."\nTitle: ".($data['distillogic_signer_title'] ?? 'Managing Director')."\nAddress: Zoodoxou Pigis 11, Patras, Greece", $text);
    $text = str_replace('[ADDRESS]', (string)($data['client_address'] ?? '[CLIENT ADDRESS]'), $text);
    $text = str_replace("Attention: [NAME]\nTitle: [TITLE]\nAddress: ".($data['client_address'] ?? '[CLIENT ADDRESS]')."\nEmail: [EMAIL]", "Attention: ".($data['client_signer_name'] ?? '[NAME]')."\nTitle: ".($data['client_signer_title'] ?? '[TITLE]')."\nAddress: ".($data['client_address'] ?? '[CLIENT ADDRESS]')."\nEmail: ".($data['client_email'] ?? '[EMAIL]'), $text);
    $text = str_replace('[EMAIL]', (string)($data['distillogic_signer_email'] ?? 'melas@distillogic.gr'), $text);

    $lines = preg_split('/\R/u', $text) ?: [];
    $content = '';
    $skipCover = true;
    $inSignatures = false;
    $sectionOpen = false;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($skipCover) {
            if ($line === '1. PARTIES') $skipCover = false; else continue;
        }
        if ($line === '') continue;
        if ($line === 'SIGNATURES') {
            if ($sectionOpen) { $content .= '</section>'; $sectionOpen = false; }
            $inSignatures = true;
            $content .= '<style>.signatures.bilateral-signatures article{height:160mm;min-height:160mm;display:flex;flex-direction:column}.signature-identity{min-height:64mm}.signature-controls{margin-top:auto}.signature-controls p{margin:0 0 5px}.signature-space{height:68px;border:1px dashed #8aa8ce;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#718399;font-weight:bold}.date-space{height:34px;border-bottom:1px solid #596b80;display:flex;align-items:center;color:#176afc;font-weight:bold}.stamp-space{height:66px;border:1px dashed #8aa8ce;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#718399;font-weight:bold}</style><section class="page-break"><h2>SIGNATURES</h2><div class="signatures bilateral-signatures"><article><h3>FOR DISTILLOGIC</h3><div class="signature-identity"><p><strong>Legal Entity</strong><br>S. D. MELAS TRADING BUSINESS</p><p><strong>Trading Name</strong><br>DISTILLOGIC TECHNOLOGIES</p><p><strong>Name</strong><br>'.e((string)($data['distillogic_signer_name'] ?? 'Spiros Melas')).'</p><p><strong>Title</strong><br>'.e((string)($data['distillogic_signer_title'] ?? 'Managing Director')).'</p></div><div class="signature-controls"><p><strong>Signature</strong></p><div class="signature-space">AUTHORISED SIGNATURE PLACEHOLDER</div><p><strong>Date</strong></p><div class="date-space"></div><p><strong>Stamp</strong></p><div class="stamp-space">COMPANY STAMP PLACEHOLDER</div></div></article><article><h3>FOR THE CLIENT</h3><div class="signature-identity"><p><strong>Company</strong><br>'.e((string)($data['client_legal_name'] ?? '')).'</p><p><strong>Name</strong><br>'.e((string)($data['client_signer_name'] ?? '')).'</p><p><strong>Title</strong><br>'.e((string)($data['client_signer_title'] ?? '')).'</p><p><strong>Email</strong><br>'.e((string)($data['client_email'] ?? '')).'</p></div><div class="signature-controls"><p><strong>Signature</strong></p><div class="signature-space">AUTHORISED SIGNATURE PLACEHOLDER</div><p><strong>Date</strong></p><div class="date-space"></div><p><strong>Stamp</strong></p><div class="stamp-space">COMPANY STAMP PLACEHOLDER</div></div></article></div></section>';
            continue;
        }
        if ($inSignatures) {
            if (strpos($line, 'SCHEDULE 1') === 0) $inSignatures = false; else continue;
        }
        if (preg_match('/^\d+\.\s+/', $line)) { if ($sectionOpen) $content .= '</section>'; $content .= '<section class="msa-section"><h2>'.e($line).'</h2>'; $sectionOpen = true; }
        elseif (strpos($line, 'SCHEDULE ') === 0 || $line === 'LEGAL REVIEW NOTICE') { if ($sectionOpen) $content .= '</section>'; $content .= '<section class="msa-section schedule"><h2>'.e($line).'</h2>'; $sectionOpen = true; }
        elseif (substr($line, -1) === ';' || substr($line, -1) === ',') $content .= '<div class="bullet">'.e(rtrim($line, ';,')).'</div>';
        else $content .= '<p>'.e($line).'</p>';
    }
    if ($sectionOpen) $content .= '</section>';

    $content = '<style>.msa-section{break-inside:auto!important;page-break-inside:auto!important}.msa-section h2{break-after:avoid;page-break-after:avoid}.msa-section p{orphans:3;widows:3}.signatures{break-inside:avoid;page-break-inside:avoid}.signatures article{min-height:150mm!important;break-inside:avoid;page-break-inside:avoid}.stamp{margin-top:18px}.footer{margin-top:16px}</style>' . $content;
    $logo = e((string)($data['logo_url'] ?? ''));
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'.e((string)($data['msa_reference'] ?? 'MSA')).' - Master Services Agreement</title><style>
@page{size:A4;margin:18mm 17mm 19mm}*{box-sizing:border-box}body{margin:0;color:#142238;background:#fff;font:9.4pt/1.45 Arial,Helvetica,sans-serif}.document{max-width:176mm;margin:auto}.brand{display:flex;justify-content:space-between;align-items:center;border-bottom:3px solid #176afc;padding-bottom:12px;margin-bottom:18px}.brand img{width:245px;max-width:57%;height:auto}.meta{text-align:right;color:#5a6b82;font-size:8.5pt}.meta strong{color:#176afc;letter-spacing:.1em}.cover{min-height:255mm;display:flex;flex-direction:column}.cover h1{font-size:31pt;line-height:1.04;margin:37mm 0 8px;color:#071a33}.cover h3{font-size:15pt;color:#176afc;margin:0}.cover-grid{margin-top:23mm;border:1px solid #c1d1e7;border-radius:12px;padding:18px;display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;background:#f5f8fd}.cover-grid strong{display:block;font-size:8pt;text-transform:uppercase;letter-spacing:.07em;color:#607086}.cover-note{margin-top:auto;color:#607086}.msa-section{margin:0 0 13px}.msa-section h2{margin:0 0 7px;padding:8px 10px;background:#071a33;color:#fff;font-size:10.8pt}.msa-section p{margin:0 0 6px;text-align:justify}.bullet{margin:2px 0 2px 16px}.bullet:before{content:"•";color:#176afc;font-weight:bold;margin-left:-12px;margin-right:7px}.schedule h2{background:#176afc}.page-break{break-before:page}.signatures{display:grid;grid-template-columns:1fr 1fr;gap:18px}.signatures article{border:1px solid #afbed0;border-radius:10px;padding:16px;min-height:205mm}.signatures h3{background:#071a33;color:#fff;margin:-16px -16px 18px;padding:11px 16px}.signature-line{height:34px;border-bottom:1px solid #596b80}.stamp{height:55px;border:1px dashed #8aa8ce;border-radius:8px;margin-top:20px;display:flex;align-items:center;justify-content:center;color:#176afc;font-weight:bold}.footer{text-align:center;color:#617086;font-size:8pt;margin-top:18px}@media print{.document{max-width:none}.msa-section h2,.signatures h3{-webkit-print-color-adjust:exact;print-color-adjust:exact}.msa-section,.signatures article{break-inside:avoid;page-break-inside:avoid}}</style></head><body><main class="document"><section class="cover"><div class="brand"><img src="'.$logo.'" alt="DISTILLOGIC TECHNOLOGIES"><div class="meta"><strong>COMMERCIAL CONFIDENTIAL</strong><br>'.e((string)($data['msa_reference'] ?? '')).'<br>Version '.e((string)($data['version'] ?? '1.0')).'</div></div><h1>MASTER SERVICES<br>AGREEMENT</h1><h3>DISTILLOGIC TECHNOLOGIES x '.e((string)($data['client_legal_name'] ?? 'CLIENT')).'</h3><div class="cover-grid"><div><strong>Effective Date</strong>'.e((string)($data['effective_date_display'] ?? '')).'</div><div><strong>Initial Term</strong>'.e((string)($data['initial_term'] ?? '')).'</div><div><strong>Client</strong>'.e((string)($data['client_legal_name'] ?? '')).'</div><div><strong>Governing Law</strong>Greece</div></div><div class="cover-note">S. D. MELAS TRADING BUSINESS<br>trading as DISTILLOGIC TECHNOLOGIES<br>Zoodoxou Pigis 11, Patras, Greece</div></section><div class="page-break"><div class="brand"><img src="'.$logo.'" alt="DISTILLOGIC TECHNOLOGIES"><div class="meta"><strong>COMMERCIAL CONFIDENTIAL</strong><br>'.e((string)($data['msa_reference'] ?? '')).'</div></div>'.$content.'<div class="footer">DISTILLOGIC TECHNOLOGIES - Master Services Agreement - '.e((string)($data['msa_reference'] ?? '')).'<br>Legal review required before execution.</div></div></main></body></html>';
}
