
# SNN Tickets

A comprehensive WordPress plugin for event ticket generation, management, and validation with QR codes, batch email invitations, and public scanning capabilities.

<img width="1721" height="889" alt="image" src="https://github.com/user-attachments/assets/80f93081-9f35-4905-821b-3270a8c602d0" />

<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/0c9d0e08-4c90-44f6-99ea-254b0a62deb1" />
<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/5c9e4115-63c5-4e79-85a1-e6d20b30dad4" />

## 🎫 Core Features

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

## 🚀 How It Works

### **1. Installation & Setup**
- Upload the plugin to your WordPress `/wp-content/plugins/` directory
- Activate "SNN Tickets" from the WordPress admin plugins page
- Access the ticket system from the "Tickets" menu in your WordPress admin

### **2. Creating Ticket Lists**
- Go to **Tickets → Generator** to create a new ticket list
- Choose between manual ticket creation or CSV import
- Set up your ticket parameters (quantity, naming, etc.)

### **3. Managing Tickets**
- View all tickets from the **Dashboard** with comprehensive statistics
- Edit ticket information inline (names, emails, etc.)
- Track validation status and activity for each ticket

### **4. Email Campaigns**
- Navigate to **Tickets → Mailer** to send invitations
- Create custom email templates with QR codes and dynamic content
- Configure batch settings to control email sending speed
- Send emails in batches to avoid server limits

### **5. Public Validation**
- Add `[tickets_scan_page]` shortcode to any page or post
- Visitors can scan QR codes or enter ticket codes manually
- Real-time validation with instant feedback
- Track all validation attempts and timestamps

## 📋 Usage Examples

### **Event Management**
Perfect for conferences, workshops, concerts, and meetups where you need to:
- Generate hundreds of tickets quickly
- Send personalized invitations with QR codes
- Validate tickets at entry points
- Track attendance and validation statistics

### **Membership Systems**
Ideal for exclusive events, member-only gatherings, or VIP access:
- Import member lists from CSV
- Send batch invitations to all members
- Use QR codes for quick member verification
- Track member participation

### **Marketing Campaigns**
Great for promotional events and limited-time offers:
- Generate unique promotional codes
- Send targeted email campaigns
- Track redemption rates
- Manage multiple campaigns simultaneously

## ⚙️ Configuration Options

### **Batch Email Settings**
- **Batch Size**: Configure how many emails to send per batch (default: 10)
- **Batch Delay**: Set delay between batches in seconds (default: 2)
- **Template Management**: Create, edit, and delete email templates

### **Email Template Variables**
Use these dynamic placeholders in your email templates:
- `{name}` - Recipient's name
- `{email}` - Recipient's email address
- `{ticket_code}` - Unique ticket code
- `{qr_image}` - QR code image (auto-generated or uploaded)

### **Shortcode Options**
- `[tickets_scan_page]` - Creates a public ticket validation page
- Responsive design with mobile-friendly interface
- Real-time validation without page refresh

## 🛠️ Technical Requirements

- **WordPress**: 5.0 or higher
- **PHP**: 8.1 or higher
- **MySQL**: 5.6 or higher
- **Server**: Supports email sending (for invitation features)

## 📊 Admin Interface

The plugin adds a comprehensive admin interface with three main sections:

1. **Dashboard**: Overview statistics, recent activity, and ticket management
2. **Tickets Generator**: Create tickets manually or import from CSV
3. **Tickets Mailer**: Send email invitations and manage templates

Each section is designed for efficiency and ease of use, with modern styling and intuitive controls.
