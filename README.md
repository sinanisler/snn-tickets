
# SNN Tickets

WordPress plugin for event ticket generation, management, and validation with QR codes, batch email invitations, and public scanning capabilities.

<img width="1721" height="889" alt="image" src="https://github.com/user-attachments/assets/80f93081-9f35-4905-821b-3270a8c602d0" />

<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/0c9d0e08-4c90-44f6-99ea-254b0a62deb1" />
<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/5c9e4115-63c5-4e79-85a1-e6d20b30dad4" />

## 🎫 Features

### **Ticket Generation & Management**
- **Manual Ticket Creation**: Generate individual tickets with unique codes
- **Bulk Ticket Generation**: Create hundreds of tickets at once with custom parameters
- **CSV Import**: Import ticket data from CSV files with name and email information
- **CSV Template Download**: Get a pre-formatted CSV template for easy data import
- **Ticket Lists Management**: Organize tickets into separate lists/events
- **Unique Ticket Codes**: Auto-generated secure ticket codes for each ticket
- **Inline Editing**: Edit ticket information directly from the admin dashboard

### **Email System & Invitations**
- **Batch Email Processing**: Send emails in configurable batches to prevent server overload
- **Customizable Email Templates**: Create, save, and manage multiple email templates
- **Template Variables**: Use dynamic placeholders like `{name}`, `{email}`, `{ticket_code}`, `{qr_image}`
- **QR Code Integration**: Automatically generate and embed QR codes in emails
- **Email Queue Management**: Process large email lists efficiently with batch delays
- **Single Email Sending**: Send individual invitations with AJAX processing
- **Email Template Library**: Save and reuse email templates for different events

### **QR Code Features**
- **Automatic QR Generation**: Create QR codes for each ticket automatically
- **Custom QR Upload**: Upload custom QR code images for tickets
- **QR Code Embedding**: Include QR codes in email invitations
- **Validation URLs**: QR codes link to validation pages for easy scanning

### **Ticket Validation & Scanning**
- **Public Scanning Page**: Use `[tickets_scan_page]` shortcode for public validation
- **Real-time Validation**: AJAX-powered ticket validation without page refresh
- **Validation Tracking**: Track validation count and last validated timestamp
- **Mobile-Friendly Scanner**: Responsive design for mobile ticket scanning
- **Instant Feedback**: Visual feedback for valid/invalid/already used tickets

### **Advanced Dashboard & Analytics**
- **Comprehensive Statistics**: View total lists, tickets, validated tickets, and email counts
- **Recent Activity Feed**: Monitor latest ticket creations and validations
- **List Overview**: Quick stats for each ticket list with progress indicators
- **Inline Management**: Edit ticket details directly from the dashboard
- **Search & Filter**: Find tickets quickly with built-in search functionality

### **Technical Features**
- **Database Optimization**: Efficient MySQL tables with proper indexing
- **AJAX Integration**: Smooth user experience with asynchronous operations
- **Batch Processing**: Configurable batch sizes and delays for email sending
- **Security**: Proper capability checks and nonce verification
- **Responsive Design**: Modern UI that works on all devices
- **WordPress Integration**: Native WordPress admin interface and hooks

## 📊 Admin Interface


1. **Dashboard**: Overview statistics, recent activity, and ticket management
2. **Tickets Generator**: Create tickets manually or import from CSV
3. **Tickets Mailer**: Send email invitations and manage templates

