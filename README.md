# Elegant ShortURL & Social Share for WordPress 🔗✨

ปลั๊กอิน WordPress สำหรับสร้าง **Short URL (ลิงก์ย่อ)** บนโดเมนของตนเองอัตโนมัติ (เช่น `yoursite.com/s/xyz`) พร้อมหน้าต่าง Modal แชร์ไปยัง Social Media และคัดลอกลิงก์ที่ถอดแบบดีไซน์มาจากสื่อออนไลน์ชั้นนำระดับประเทศ (The Standard / Blockdit) — **สวยงาม ทันสมัย สะอาดตา และดูน่าเชื่อถือสูง**

---

## 📸 ดีไซน์และหน้าตา UI (The Standard Style)

หน้าต่างป๊อปอัปสไตล์ Dark Glassmorphism เรียบหรู:
- 🟢 **แชท (LINE)**: แชร์เข้าห้องแชท LINE ได้ทันที
- 🔵 **Facebook**: แชร์ไปยังฟีด Facebook
- ⚪ **คัดลอก URL**: คัดลอก Short URL อัตโนมัติ พร้อมไอคอนติ๊กถูกและ Toast Alert
- ⚪ **X**: โพสต์ข้อความพร้อม Short URL บน X (Twitter)

---

## จุดเด่นและฟีเจอร์เด่น (Key Features)

1. **Self-Hosted Short URL ปลอดภัยและรวดเร็ว**:
   - สร้างลิงก์ย่อภายในโดเมนของคุณเอง ไม่ต้องพึ่งพาบริการภายนอก เช่น bit.ly ป้องกันปัญหาลิงก์เสียหรือโดนแบน
   - รองรับการตั้ง Prefix เองได้ เช่น `/s/`, `/go/`, `/link/`
   - ทำงานผ่าน HTTP 301 Permanent Redirect เป็นมิตรกับ SEO และโหลดรวดเร็ว

2. **ระบบสถิติการคลิก (Click Counter)**:
   - บันทึกจำนวนครั้งที่มีการเปิดเข้าชมผ่าน Short URL แต่ละโพสต์
   - มี Meta Box แสดงสถิติและปุ่ม Copy ในหน้าแก้ไข Post/Page

3. **ปรับแต่งและควบคุมได้ง่ายในหลังบ้าน (WordPress Admin)**:
   - เมนูตั้งค่า **Settings > Short URL & Share**
   - เลือกว่าจะให้แสดงท้ายเนื้อหาอัตโนมัติในประเภทโพสต์ใดได้บ้าง (Post / Page)
   - เลือกระหว่าง **Dark Theme** หรือ **Light Theme**
   - รองรับ Shortcode สำหรับนำไปวาง ณ จุดใดก็ได้ตามต้องการ:
     ```html
     [elegant_share_button]
     ```
     หรือ
     ```html
     [elegant_shorturl]
     ```

---

## โครงสร้างโฟลเดอร์ของปลั๊กอิน

```
pluginwpshorturl/
├── pluginwpshorturl.php      # ไฟล์หลักของปลั๊กอิน (Logic, Routing, Admin Settings, Hooks)
├── assets/
│   ├── css/
│   │   ├── frontend.css      # สไตล์ของปุ่มแชร์และ Modal Popup หน้าเว็บ
│   │   └── admin.css         # สไตล์หน้าจอตั้งค่าใน WP Admin
│   └── js/
│       └── frontend.js       # ระบบเปิด/ปิด Modal และคัดลอกลงคลิปบอร์ด (Vanilla JS น้ำหนักเบา)
└── README.md
```

---

## Author
พัฒนาโดย **@iOhm** ([@ohmpwa](https://github.com/ohmpwa))

## License
GPL v2 or later
