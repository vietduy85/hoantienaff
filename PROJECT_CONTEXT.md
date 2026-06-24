\# HOANTIENAFF PROJECT CONTEXT



\## Mục tiêu dự án



Website hoàn tiền khi mua sắm.



Domain:



hoantien.xyz



Người dùng:



1\. Dán link sản phẩm

2\. Tạo link affiliate

3\. Mua hàng qua link

4\. Nhận hoàn tiền



\---



\## Kiến trúc



Laravel 12



Provider Pattern



Affiliate Worker (NodeJS)



Playwright



\---



\## Luồng hệ thống



Dashboard



↓



ProviderFactory



↓



ShopeeProvider



↓



AffiliateWorkerClient



↓



Affiliate Worker



↓



Playwright



↓



Shopee Affiliate



\---



\## Nguyên tắc



Không gọi Playwright trực tiếp từ Laravel.



Laravel chỉ giao tiếp với Worker thông qua HTTP.



\---



\## Các Provider hiện có



\* Shopee

\* Lazada

\* TikTok

\* Long Châu

\* Pharmacity

\* Traveloka

\* Agoda

\* Booking



\---



\## Trạng thái hiện tại



\### TEST 5



ProviderFactory PASS



\### TEST 5.5



Dependency Injection PASS



\### TEST 6



Laravel ↔ Worker PASS



\### TEST 7



Playwright PASS



Máy nhà: PASS



Máy công ty: PASS



\---



\## Bước tiếp theo



TEST 8A



Đăng nhập Shopee Affiliate



Lưu session



storage/shopee-state.json



\---



\## Chưa làm



\* Tạo affiliate link thật

\* Xử lý captcha tự động

\* Cashback thật

\* Shopee API



\---



\## Mục tiêu dài hạn



Khi có Shopee API:



ShopeeProvider



sẽ chuyển từ:



Worker → Playwright



sang:



Shopee API



mà không ảnh hưởng Dashboard.



