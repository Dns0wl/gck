@page {
    margin: 0;
}
body {
    font-family: 'Inter', sans-serif;
    color: #1f1f24;
    background: #f8f8fb;
    margin: 0;
}
.manual-wrapper {
    padding: 24mm;
    background: #fff;
    min-height: 297mm;
    background-image: radial-gradient(circle at 1px 1px, rgba(0,0,0,0.04) 1px, transparent 0);
    background-size: 12px 12px;
}
.manual-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #e2e2eb;
    padding-bottom: 10mm;
}
.brand-logo {
    width: 48mm;
    height: auto;
}
.manual-brand h1 {
    font-size: 18pt;
    margin: 0;
}
.manual-brand p {
    margin: 4px 0 0;
    font-size: 11pt;
    color: #717a8d;
}
.manual-serial {
    text-align: right;
    font-size: 10pt;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}
.manual-serial strong {
    display: block;
    font-size: 18pt;
    color: #1e6f5c;
}
.manual-hero {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20mm;
    padding: 16mm 0;
}
.manual-hero h2 {
    font-size: 20pt;
    margin: 0 0 6mm;
}
.manual-hero .thanks {
    font-size: 12pt;
    line-height: 1.5;
}
.manual-hero .order-note {
    font-size: 11pt;
    color: #6b7080;
    margin: -2mm 0 4mm;
}
.manual-customer {
    display: flex;
    gap: 12mm;
    margin-top: 6mm;
    font-size: 11pt;
    flex-wrap: wrap;
}
.manual-customer > div {
    min-width: 35mm;
}
.manual-customer small {
    display: block;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: #7a8090;
    margin-bottom: 2mm;
}
.manual-customer strong {
    font-size: 13pt;
    color: #1e6f5c;
}
.manual-qr {
    width: 55mm;
    text-align: center;
}
.manual-qr svg {
    width: 55mm;
    height: 55mm;
}
.manual-content {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 10mm;
}
.manual-block {
    background: rgba(238, 240, 248, 0.7);
    padding: 8mm;
    border-radius: 10mm;
}
.manual-block h3 {
    margin-top: 0;
    font-size: 12pt;
    text-transform: uppercase;
    letter-spacing: 0.12em;
}
.manual-block ul {
    margin: 0;
    padding-left: 14px;
    font-size: 11pt;
}
.manual-footer {
    border-top: 2px solid #e2e2eb;
    margin-top: 20mm;
    padding-top: 6mm;
    display: flex;
    justify-content: space-between;
    font-size: 10pt;
    color: #6a6e7a;
}
