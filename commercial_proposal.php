<?php
require_once 'init.php';
require_once 'session-check.php';

// Ensure user has adequate permissions
$userId = getCurrentUserId();
if (!isAdmin()) {
    die("Unauthorized access.");
}

$pageTitle = 'Estate Hub - Commercial Proposal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap');

        :root {
            --brand-blue: #0ea5e9;
            --brand-dark: #0f172a;
            --text-main: #334155;
            --text-light: #64748b;
            --border: #e2e8f0;
            --bg-light: #f8fafc;
        }

        body { 
            font-family: 'Inter', sans-serif; 
            color: var(--text-main); 
            background: #e2e8f0; 
            margin: 0; 
            padding: 2rem; 
            line-height: 1.4;
        }

        .document-container {
            max-width: 210mm; /* A4 Width */
            margin: 0 auto;
            background: #fff;
            padding: 15mm 20mm;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            border-top: 8px solid var(--brand-blue);
        }

        /* Header */
        .header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid var(--border); padding-bottom: 15px; margin-bottom: 20px; }
        .header-left img { height: 45px; margin-bottom: 10px; }
        .header-left h1 { margin: 0; font-size: 1.8rem; color: var(--brand-dark); font-weight: 800; letter-spacing: -0.5px; }
        .header-right { text-align: right; color: var(--text-light); font-size: 0.85rem; }
        .header-right strong { color: var(--brand-dark); }

        /* Intro */
        .intro { margin-bottom: 20px; font-size: 0.95rem; }
        .intro p { margin: 0 0 10px 0; }

        /* Modules Grid - Ultra Compact for One-Pager */
        .section-title { font-size: 1.1rem; color: var(--brand-blue); border-bottom: 1px solid var(--border); padding-bottom: 5px; margin-bottom: 15px; text-transform: uppercase; font-weight: 800; letter-spacing: 0.5px; }
        
        .modules-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .module-box { background: var(--bg-light); border: 1px solid var(--border); border-radius: 6px; padding: 12px; page-break-inside: avoid; }
        .module-box h3 { margin: 0 0 5px 0; font-size: 0.9rem; color: var(--brand-dark); display: flex; align-items: center; gap: 6px; }
        .module-desc { font-size: 0.75rem; color: var(--text-light); margin-bottom: 6px; }
        .module-val { font-size: 0.75rem; color: var(--brand-dark); font-weight: 600; border-top: 1px dashed #cbd5e1; padding-top: 6px; }

        /* Commercial Proposal */
        .pricing-section { background: var(--brand-dark); color: #fff; border-radius: 8px; padding: 15px 20px; margin-bottom: 20px; page-break-inside: avoid; }
        .pricing-section h2 { margin: 0 0 15px 0; font-size: 1.2rem; color: var(--brand-blue); border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 8px; }
        
        .pricing-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .price-card { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; padding: 12px; }
        .price-card.sponsor { background: rgba(14, 165, 233, 0.1); border-color: var(--brand-blue); }
        
        .price-title { font-size: 0.9rem; font-weight: bold; margin-bottom: 5px; color: #fff; }
        .price-entities { font-size: 0.7rem; color: #94a3b8; margin-bottom: 10px; min-height: 28px; }
        .price-cost { font-size: 1.2rem; font-weight: 800; color: var(--brand-blue); margin-bottom: 2px; }
        .price-old { font-size: 0.75rem; color: #64748b; text-decoration: line-through; }
        
        /* Terms */
        .terms { font-size: 0.75rem; color: var(--text-light); background: var(--bg-light); padding: 10px 15px; border-radius: 6px; border-left: 3px solid var(--brand-blue); page-break-inside: avoid; }
        
        /* Page 2: Comparison Chart */
        .page-break { margin-top: 40px; }
        
        .comp-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; margin-bottom: 20px; page-break-inside: avoid; }
        .comp-table th { background: var(--brand-dark); color: #fff; text-align: left; padding: 10px; border: 1px solid var(--border); }
        .comp-table th.highlight { background: var(--brand-blue); }
        .comp-table td { padding: 10px; border: 1px solid var(--border); vertical-align: top; }
        .comp-table tr:nth-child(even) { background: var(--bg-light); }
        .comp-table td:nth-child(2) { background: rgba(14, 165, 233, 0.05); font-weight: 600; color: var(--brand-dark); border-left: 2px solid var(--brand-blue); border-right: 2px solid var(--brand-blue); }
        
        .strat-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; page-break-inside: avoid; }
        .strat-box { border-left: 3px solid var(--brand-blue); padding: 12px; background: var(--bg-light); }
        .strat-box h4 { margin: 0 0 5px 0; font-size: 0.85rem; color: var(--brand-dark); }
        .strat-box p { margin: 0; font-size: 0.75rem; color: var(--text-light); }

        .btn-print { position: fixed; bottom: 30px; right: 30px; background: var(--brand-blue); color: white; border: none; padding: 15px 25px; border-radius: 30px; font-weight: bold; font-size: 1rem; cursor: pointer; box-shadow: 0 10px 25px rgba(14, 165, 233, 0.4); z-index: 1000; }
        
        @media print {
            body { background: #fff; padding: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .document-container { box-shadow: none; max-width: 100%; padding: 0; border-top: none; }
            .btn-print { display: none !important; }
            .page-break { page-break-before: always; margin-top: 0; padding-top: 10mm; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Print / Save to PDF</button>

<div class="document-container">
    
    <div class="header">
        <div class="header-left">
            <img src="logo.png" alt="Estate Hub" onerror="this.src='logo_BKP.png'">
            <h1>Cerulean Labs Ltd.</h1>
        </div>
        <div class="header-right">
            <div><strong>Enterprise Licensing Agreement</strong></div>
            <div><strong>Prepared For:</strong> Mark Agius - Director</div>
            <div><strong>Date:</strong> <?= date('F Y') ?></div>
        </div>
    </div>

    <div class="intro">
        <strong>Estate Hub: The OS for Maltese Real Estate & Construction</strong><br>
        Estate Hub is a centralized, cloud-based operating system designed specifically for the Maltese construction lifecycle. Instead of paying for bloated software, Estate Hub utilizes a modular enterprise architecture, allowing entities to license only the specific toolsets they require.
    </div>

    <div class="section-title">System Architecture & Modules</div>
    
    <div class="modules-grid">
        <div class="module-box">
            <h3>📦 1. Core Portfolio & Dev. Tracker</h3>
            <div class="module-desc">Master project registry, PA Permit tracking, and automated assignment of Periti. Includes Eapps direct linking and API add-ons.</div>
            <div class="module-val">Value: Instant visibility into the entire portfolio.</div>
        </div>
        <div class="module-box">
            <h3>🚧 2. Pre-Construction & BCA</h3>
            <div class="module-desc">Step-by-step tracking of Geological tests, Condition Reports, Method Statements, Insurance, and BCA Clearances.</div>
            <div class="module-val">Value: Prevents costly site delays and BCA fines.</div>
        </div>
        <div class="module-box">
            <h3>🏗️ 3. Site Execution Engine</h3>
            <div class="module-desc">Block-by-block structural tracking. Automated Turnkey/Finishes matrices with dynamic completion % calculation.</div>
            <div class="module-val">Value: Real-time progress data without site visits.</div>
        </div>
        <div class="module-box">
            <h3>⚠️ 4. OHSA Compliance Radar</h3>
            <div class="module-desc">Dedicated OHSA status tracking, on-site safety alerts, and centralized risk logging protocols.</div>
            <div class="module-val">Value: Legal risk mitigation for Directorship.</div>
        </div>
        <div class="module-box">
            <h3>💼 5. Commercial Quoting (Work Sales)</h3>
            <div class="module-desc">Automated Finishes Calculator, Standard BoQ builder, RFP generation, and milestone Interim Claim invoicing.</div>
            <div class="module-val">Value: Eliminates human error in pricing and VAT math.</div>
        </div>
        <div class="module-box">
            <h3>🤝 6. Subcontractor Ledger</h3>
            <div class="module-desc">Link Subcontractor BoQs directly to physical site progress. Issue PDF payment certificates and track True Liability.</div>
            <div class="module-val">Value: Stop overpaying for uncompleted site works.</div>
        </div>
        <div class="module-box">
            <h3>📁 7. Cloud Document Vault</h3>
            <div class="module-desc">Direct-to-cloud secure storage with automated 30-day countdown alarms for expiring permits and insurances.</div>
            <div class="module-val">Value: Never miss a critical renewal deadline.</div>
        </div>
        <div class="module-box">
            <h3>🔑 8. Real Estate Sales Hub</h3>
            <div class="module-desc">Live tracking of unit statuses (Available/Hold/Sold), dynamic pricing updates, and Interactive SVG Floorplans.</div>
            <div class="module-val">Value: Prevents double-selling across external agents.</div>
        </div>
    </div>

    <div class="pricing-section">
        <h2>Commercial Proposal: 12-Month Pilot Program</h2>
        <p style="font-size: 0.85rem; color: #cbd5e1; margin-top: -10px; margin-bottom: 15px;">
            To support co-development, all group entities receive a <strong>50% early-adopter discount</strong> for the first 12 months. Includes the <em>Daily Executive Flash Report</em> perk globally.
        </p>

        <div class="pricing-grid">
            <div class="price-card">
                <div class="price-title">Invoice 1: PRA & Excel Group</div>
                <div class="price-entities">Billed to: PRA Construction Ltd<br>Covers: PRA + All Excel Subsidiaries<br>Modules: 1, 2, 3, 4, 5, 6, 7, 8</div>
                <div class="price-old">Standard Value: €1,200 / month</div>
                <div class="price-cost">€600 / month</div>
            </div>

            <div class="price-card">
                <div class="price-title">Invoice 2: Next Group</div>
                <div class="price-entities">Billed to: Next Construction Ltd<br>Covers: Next Const. + Next Developers<br>Modules: 1, 2, 3, 4, 5, 6, 7, 8</div>
                <div class="price-old">Standard Value: €1000 / month</div>
                <div class="price-cost">€500 / month</div>
            </div>

            <div class="price-card">
                <div class="price-title">Invoice 3: PRAX Concrete</div>
                <div class="price-entities">Billed to: PRAX Concrete Ltd<br>Covers: PRAX Concrete Only<br>Modules: 5 (Commercial Quoting)</div>
                <div class="price-old">Standard Value: €180 / month</div>
                <div class="price-cost">€90 / month</div>
            </div>

            <div class="price-card sponsor">
                <div class="price-title">Zero-Invoice: Sponsor License</div>
                <div class="price-entities" style="color: #bae6fd;">Beneficiaries: All Blue Clay Companies + Joseph Agius & Sons<br>Modules: 1, 2, 3, 4</div>
                <div class="price-old" style="color: #7dd3fc;">Standard Value: €800+ / month</div>
                <div class="price-cost" style="color: #fff;">€0.00 (Lifetime Waiver)</div>
            </div>
        </div>
    </div>

    <div class="terms">
        <strong>Billing & Implementation Terms:</strong><br>
        1. <strong>Commencement:</strong> Formal billing and the 12-month pilot phase period will tentatively commence at the <strong>End of June 2026</strong>. Prices are Exc. VAT.<br>
        2. <strong>Users & Projects:</strong> Subscriptions are flat-rate per group. There are no per-user or per-project limits.<br>
        3. <strong>Sponsor License:</strong> The zero-invoice license for Blue Clay and Joseph Agius & Sons is granted in perpetuity as a gesture of appreciation for supporting the incubation and initial development of the platform.<br>
        4. <strong>Post-Pilot:</strong> At the conclusion of the 12-month pilot phase (June 2027), standard rates will automatically apply unless renegotiated.
    </div>

    <div class="page-break"></div>
    
    <div class="header">
        <div class="header-left">
            <h1 style="font-size: 1.4rem;">Appendix A: Market & Value Analysis</h1>
        </div>
        <div class="header-right">
            <div><strong>Cerulean Labs Ltd.</strong></div>
        </div>
    </div>

    <div class="intro">
        <p>To contextualize the proposed licensing fees, the following matrix compares <strong>Estate Hub</strong> against the prevailing open-market alternatives available to Maltese construction and real estate firms. The analysis demonstrates the significant operational and financial advantages of utilizing a natively developed system.</p>
    </div>

    <div class="section-title">Competitive Pricing & Capability Matrix</div>

    <table class="comp-table">
        <thead>
            <tr>
                <th style="width: 20%;">Feature / Aspect</th>
                <th class="highlight" style="width: 26%;">Estate Hub (Proposed)</th>
                <th style="width: 27%;">Tier 1 Construction Software<br><span style="font-size:0.7rem; font-weight:normal;">(e.g., Procore)</span></th>
                <th style="width: 27%;">Generic Cloud ERP<br><span style="font-size:0.7rem; font-weight:normal;">(e.g., Odoo, MS Dynamics)</span></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Pricing Model</strong></td>
                <td>Flat Fee per Subsidiary.<br>Unlimited Users.</td>
                <td>Percentage of Annual Construction Volume (Revenue).</td>
                <td>Per-User / Per-Month License.<br>Penalizes team growth.</td>
            </tr>
            <tr>
                <td><strong>Setup / Implementation Fees</strong></td>
                <td><strong>€0</strong></td>
                <td>€5,000 - €10,000+</td>
                <td>€15,000 - €40,000+ (Requires local agency custom development).</td>
            </tr>
            <tr>
                <td><strong>Estimated Monthly Cost</strong><br><span style="font-size:0.7rem; font-weight:normal;">(For a 20-person group)</span></td>
                <td><strong>€600 - €1,200</strong><br><span style="font-size:0.7rem;">(Fixed)</span></td>
                <td>€2,000 - €4,000+<br><span style="font-size:0.7rem;">(Scales up with your revenue)</span></td>
                <td>€1,000 - €3,000+<br><span style="font-size:0.7rem;">(Scales up with headcount)</span></td>
            </tr>
            <tr>
                <td><strong>Malta-Specific Logic</strong><br><span style="font-size:0.7rem; font-weight:normal;">(BCA, OHSA, Eapps)</span></td>
                <td><strong>Native.</strong> Built specifically around Maltese law and local bottlenecks.</td>
                <td><strong>None.</strong> Uses generic US/UK compliance frameworks.</td>
                <td><strong>None.</strong> Must be custom-built from scratch by expensive local developers.</td>
            </tr>
            <tr>
                <td><strong>Sales & Real Estate Integration</strong></td>
                <td><strong>Native.</strong> Connects live site finishes directly to the Real Estate Sales Hub.</td>
                <td><strong>None.</strong> Requires a secondary subscription (e.g., Arthur Online) at €500+/mo.</td>
                <td>Requires expensive custom API bridges between the ERP and the website.</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Strategic Advantages (The "Why Estate Hub?")</div>
    
    <div class="strat-grid">
        <div class="strat-box">
            <h4>1. No "Per-Seat" Penalty</h4>
            <p>Traditional ERPs charge per user. If the group hires a new site manager, QS, or sales agent, the monthly software bill increases immediately. Estate Hub’s flat-fee model encourages total company adoption without budget anxiety.</p>
        </div>
        <div class="strat-box">
            <h4>2. Zero Customization Traps</h4>
            <p>Off-the-shelf software forces construction firms to change their operational habits to fit the software's limitations. Estate Hub was built ground-up to reflect exactly how PRA, Excel, and Blue Clay calculate turnkey finishes, track BCA clearances, and manage local subcontractors.</p>
        </div>
        <div class="strat-box">
            <h4>3. Avoiding Five-Figure Setup Costs</h4>
            <p>To achieve the custom workflow Estate Hub provides (e.g., the Finishes Calculator or PA sync), a local tech agency implementing a generic ERP would charge tens of thousands of Euros in upfront development fees before the system even launches.</p>
        </div>
    </div>

</div>

<script>
    // Automatically trigger print dialog on load (optional, you can remove this if you prefer manual clicking)
    // window.onload = function() { window.print(); }
</script>

</body>
</html>
