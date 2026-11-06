# Elegant ShortURL & Social Share for WordPress 🔗✨

ปลั๊กอิน WordPress สำหรับสร้าง **Short URL (ลิงก์ย่อ)** บนโดเมนของตนเองอัตโนมัติ (เช่น `yoursite.com/s/xyz`) พร้อมกล่องและปุ่มแชร์ไปยัง Social Media ที่ถูกดีไซน์มาเป็นพิเศษให้ **สวยงาม ทันสมัย สะอาดตา และดูน่าเชื่อถือสูง**

---

## จุดเด่นและฟีเจอร์เด่น (Key Features)

1. **Self-Hosted Short URL ปลอดภัยและรวดเร็ว**:
   - สร้างลิงก์ย่อภายในโดเมนของคุณเอง ไม่ต้องพึ่งพาบริการภายนอก เช่น bit.ly ป้องกันปัญหาลิงก์เสียหรือโดนแบน
   - รองรับการตั้ง Prefix เองได้ เช่น `/s/`, `/go/`, `/link/`
   - ทำงานผ่าน HTTP 301 Permanent Redirect เป็นมิตรกับ SEO และโหลดรวดเร็ว

2. **ดีไซน์ปุ่มและกล่องแชร์ที่ดูสวยงาม สะอาด น่าเชื่อถือ (Credible & Clean UI)**:
   - ตราสัญลักษณ์ **Official Short Link / Verified Badge** เพิ่มความมั่นใจให้กับผู้เข้าชม
   - ช่อง Input แสดง Short URL พร้อมปุ่ม **One-Click Copy** ที่มี Animation แจ้งสถานะชัดเจน
   - ปุ่มแชร์สีและไอคอนตรงตามอัตลักษณ์ทางการของแต่ละแพลตฟอร์ม (Facebook, X / Twitter, LINE, LinkedIn, Telegram)
   - ฟังก์ชันสร้าง **QR Code Popup** สำหรับสแกนเปิดบนโทรศัพท์มือถือได้ทันที

3. **ระบบสถิติการคลิก (Click Counter)**:
   - บันทึกจำนวนครั้งที่มีการเปิดเข้าชมผ่าน Short URL แต่ละโพสต์
   - สามารถเลือกเปิด/ปิดการแสดงตัวเลขยอดเข้าชมเพื่อเพิ่ม Social Proof ได้

4. **ปรับแต่งและควบคุมได้ง่ายในหลังบ้าน (WordPress Admin)**:
   - เมนูตั้งค่า **Settings > Short URL & Share**
   - มี Meta Box แสดง Short Link และจำนวนสถิติคลิกในหน้าแก้ไขโพสต์/หน้าเพจ
   - เลือกว่าจะให้แสดงท้ายเนื้อหาอัตโนมัติในประเภทโพสต์ใดได้บ้าง (Post / Page)
   - รองรับ Shortcode `[elegant_shorturl]` สำหรับนำไปวาง ณ จุดใดก็ได้ตามต้องการ

---

## โครงสร้างโฟลเดอร์ของปลั๊กอิน

```
pluginwpshorturl/
├── pluginwpshorturl.php      # ไฟล์หลักของปลั๊กอิน (Logic, Routing, Admin Settings, Hooks)
├── assets/
│   ├── css/
│   │   ├── frontend.css      # สไตล์ของปุ่มแชร์และกล่อง Short URL หน้าเว็บ
│   │   └── admin.css         # สไตล์หน้าจอตั้งค่าใน WP Admin
│   └── js/
│       └── frontend.js       # ระบบคัดลอกลงคลิปบอร์ด, QR Code popup (Vanilla JS ไม่มี dependencies)
└── README.md
```

---

## วิธีการติดตั้งและใช้งาน (Installation)

1. ดาวน์โหลดโฟลเดอร์ `pluginwpshorturl` ไปวางไว้ที่ `/wp-content/plugins/` บน WordPress ของคุณ (หรือบีบอัดเป็น .zip แล้วอัปโหลดผ่านหน้า **Plugins > Add New > Upload Plugin**)
2. ไปที่เมนู **Plugins (ปลั๊กอิน)** ในระบบหลังบ้าน WordPress แล้วกด **Activate (เปิดใช้งาน)**
3. ปลั๊กอินจะสร้าง Rewrite Rules อัตโนมัติ (หากหน้าเว็บไม่แสดงผล ให้ไปที่เมนู **Settings > Permalinks** แล้วกด Save Changes หนึ่งครั้ง)
4. สามารถเข้าไปตั้งค่าเพิ่มเติมได้ที่ **Settings > Short URL & Share**
5. หรือใช้ Shortcode ใน Elementor / Gutenberg / Classic Editor:
   ```html
   [elegant_shorturl]
   ```
   หรือระบุหัวข้อที่ต้องการ:
   ```html
   [elegant_shorturl title="แบ่งปันบทความนี้"]
   ```

---

## License
GPL v2 or later
