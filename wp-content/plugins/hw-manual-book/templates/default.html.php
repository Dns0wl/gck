<div class="manual-wrapper">
    <header class="manual-header">
        <div class="manual-brand">
            <img src="{{logo}}" alt="Hayu Widyas" class="brand-logo" />
            <div class="brand-text">
                <h1>Hayu Widyas Handmade</h1>
                <p>{{brand_slogan}}</p>
            </div>
        </div>
        <div class="manual-serial">
            <span>Serial</span>
            <strong>{{serial_code}}</strong>
            <span>{{transaction_date}}</span>
        </div>
    </header>

    <section class="manual-hero">
        <div>
            <h2>Manual Book</h2>
            <p class="thanks">Terima kasih telah mempercayakan perjalanan Anda bersama {{product_name}}. Produk ini dibuat menggunakan {{material}} dengan pilihan kulit {{leather_type}} berwarna {{color}} ukuran {{size}}.</p>
            <div class="manual-customer">
                <div>
                    <small>Pelanggan</small>
                    <strong>{{customer_name}}</strong>
                </div>
                <div>
                    <small>Tanggal Pemesanan</small>
                    <strong>{{order_date}}</strong>
                </div>
            </div>
        </div>
        <div class="manual-qr" data-url="{{qr_url}}">
            {{qr_svg}}
            <small>Scan untuk riwayat dan tips lengkap</small>
        </div>
    </section>

    <section class="manual-content">
        <div class="manual-block">
            <h3>Genuine Leather</h3>
            <p>Setiap produk dibuat dari kulit pilihan yang akan membentuk karakter unik seiring pemakaian. Warna dan tekstur dapat berubah alami, menambah cerita perjalanan Anda.</p>
        </div>
        <div class="manual-block">
            <h3>Perawatan Harian</h3>
            <ul>
                <li>Simpan di tempat kering dengan sirkulasi udara baik.</li>
                <li>Gunakan kain microfiber lembut untuk membersihkan debu.</li>
                <li>Hindari paparan langsung sinar matahari dan cairan kimia.</li>
            </ul>
        </div>
        <div class="manual-block">
            <h3>Penyimpanan</h3>
            <p>Gunakan dustbag dan silica gel. Jangan menumpuk produk dengan berat berlebih. Biarkan udara masuk secara berkala untuk menjaga kelembutan kulit.</p>
        </div>
    </section>

    <footer class="manual-footer">
        <div>
            <strong>HW Manual Book</strong>
            <span>Generated {{generated_at}} · v{{version}}</span>
        </div>
        <div>
            <span>WhatsApp {{footer_phone}}</span>
            <span>hayuwidyas.com</span>
        </div>
    </footer>
</div>
