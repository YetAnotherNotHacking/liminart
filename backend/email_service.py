import aiosmtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
from jinja2 import Template
from config import settings
import logging
from typing import Optional

logger = logging.getLogger(__name__)

class EmailService:
    @staticmethod
    def get_email_template(template_type: str) -> str:
        """Get HTML email template by type"""
        
        if template_type == "verification":
            return """
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Pixel Canvas Account</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin: 40px 0;
        }
        .header {
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .pixel-art {
            font-size: 24px;
            margin: 10px 0;
        }
        .content {
            padding: 40px 30px;
            line-height: 1.6;
            color: #333;
        }
        .welcome-text {
            font-size: 18px;
            margin-bottom: 20px;
            color: #2c3e50;
        }
        .verify-button {
            display: inline-block;
            background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
            color: white;
            text-decoration: none;
            padding: 15px 40px;
            border-radius: 50px;
            font-weight: bold;
            font-size: 16px;
            margin: 20px 0;
            transition: transform 0.2s;
        }
        .verify-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .footer {
            background: #f8f9fa;
            padding: 30px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .pixel-grid {
            display: inline-block;
            margin: 20px 0;
        }
        .pixel {
            display: inline-block;
            width: 12px;
            height: 12px;
            margin: 1px;
        }
        .warning {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-card">
            <div class="header">
                <div class="pixel-art">🎨 ✨ 🖼️</div>
                <h1>Welcome to Pixel Canvas!</h1>
                <p>Your creative journey begins here</p>
            </div>
            
            <div class="content">
                <div class="welcome-text">
                    Hey <strong>{{ username }}</strong>! 👋
                </div>
                
                <p>Welcome to the most epic collaborative pixel art canvas on the internet! We're thrilled to have you join our community of digital artists and pixel pushers.</p>
                
                <p>But first, let's make sure it's really you. Click the magical button below to verify your email and unlock your creative superpowers:</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{ verification_url }}" class="verify-button">
                        🚀 Verify My Account
                    </a>
                </div>
                
                <div class="pixel-grid" style="text-align: center;">
                    <span class="pixel" style="background: #ff6b6b;"></span>
                    <span class="pixel" style="background: #4ecdc4;"></span>
                    <span class="pixel" style="background: #45b7d1;"></span>
                    <span class="pixel" style="background: #96ceb4;"></span>
                    <span class="pixel" style="background: #feca57;"></span>
                    <span class="pixel" style="background: #ff9ff3;"></span>
                    <span class="pixel" style="background: #54a0ff;"></span>
                    <span class="pixel" style="background: #5f27cd;"></span>
                </div>
                
                <p>Once verified, you'll be able to:</p>
                <ul>
                    <li>🎨 Place pixels on the infinite canvas</li>
                    <li>📊 Track your pixel placement statistics</li>
                    <li>🖼️ Upload a custom profile picture</li>
                    <li>⚡ Get personalized rate limits</li>
                    <li>🏆 Compete on the leaderboards</li>
                </ul>
                
                <div class="warning">
                    <strong>⏰ Hurry up!</strong> This verification link expires in 24 hours. Don't let it become a dead pixel!
                </div>
                
                <p>If the button doesn't work, copy and paste this URL into your browser:</p>
                <p style="word-break: break-all; background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace;">{{ verification_url }}</p>
            </div>
            
            <div class="footer">
                <p>Made with ❤️ by the Pixel Canvas Team</p>
                <p>If you didn't create this account, you can safely ignore this email.</p>
                <div style="margin-top: 20px;">
                    <span class="pixel" style="background: #ff6b6b;"></span>
                    <span class="pixel" style="background: #4ecdc4;"></span>
                    <span class="pixel" style="background: #45b7d1;"></span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
            """
        
        elif template_type == "welcome":
            return """
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Pixel Canvas!</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .email-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            margin: 40px 0;
        }
        .header {
            background: linear-gradient(45deg, #11998e, #38ef7d);
            padding: 40px 30px;
            text-align: center;
            color: white;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 300;
        }
        .content {
            padding: 40px 30px;
            line-height: 1.6;
            color: #333;
        }
        .cta-button {
            display: inline-block;
            background: linear-gradient(45deg, #11998e, #38ef7d);
            color: white;
            text-decoration: none;
            padding: 15px 30px;
            border-radius: 50px;
            font-weight: bold;
            margin: 10px;
        }
        .footer {
            background: #f8f9fa;
            padding: 30px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="email-card">
            <div class="header">
                <h1>🎉 Account Verified! 🎉</h1>
                <p>Welcome to the Pixel Canvas family!</p>
            </div>
            
            <div class="content">
                <p>Awesome, <strong>{{ username }}</strong>! Your account is now verified and ready to create pixel magic! ✨</p>
                
                <p>You're now part of an amazing community where every pixel tells a story. Whether you're here to create art, leave your mark, or just have fun - the canvas awaits!</p>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="{{ canvas_url }}" class="cta-button">🎨 Start Creating</a>
                    <a href="{{ profile_url }}" class="cta-button">⚙️ Setup Profile</a>
                </div>
                
                <p><strong>Pro Tips for New Artists:</strong></p>
                <ul>
                    <li>🎯 Start small - place your first pixel anywhere!</li>
                    <li>🔍 Use the zoom controls to see details</li>
                    <li>🎨 Experiment with different colors</li>
                    <li>👥 Collaborate with other artists</li>
                    <li>📸 Upload a profile picture to stand out</li>
                </ul>
                
                <p>Happy pixeling! 🎨</p>
            </div>
            
            <div class="footer">
                <p>The Pixel Canvas Team</p>
            </div>
        </div>
    </div>
</body>
</html>
            """
        
        else:
            return "<p>{{ content }}</p>"

    @staticmethod
    async def send_verification_email(email: str, username: str, verification_token: str) -> bool:
        """Send email verification with beautiful HTML template"""
        try:
            if not all([settings.smtp_server, settings.smtp_username, settings.smtp_password]):
                logger.warning("Email settings not configured, skipping email send")
                return False

            verification_url = f"{settings.frontend_url}/verify?token={verification_token}"
            
            template = Template(EmailService.get_email_template("verification"))
            html_content = template.render(
                username=username,
                verification_url=verification_url
            )
            
            # Create message
            message = MIMEMultipart("alternative")
            message["Subject"] = "🎨 Verify Your Pixel Canvas Account"
            message["From"] = settings.from_email
            message["To"] = email
            
            # Create HTML part
            html_part = MIMEText(html_content, "html")
            message.attach(html_part)
            
            # Send email
            await aiosmtplib.send(
                message,
                hostname=settings.smtp_server,
                port=settings.smtp_port,
                start_tls=True,
                username=settings.smtp_username,
                password=settings.smtp_password,
            )
            
            logger.info(f"Verification email sent to {email}")
            return True
            
        except Exception as e:
            logger.error(f"Failed to send verification email to {email}: {str(e)}")
            return False

    @staticmethod
    async def send_welcome_email(email: str, username: str) -> bool:
        """Send welcome email after verification"""
        try:
            if not all([settings.smtp_server, settings.smtp_username, settings.smtp_password]):
                return False

            template = Template(EmailService.get_email_template("welcome"))
            html_content = template.render(
                username=username,
                canvas_url=settings.frontend_url,
                profile_url=f"{settings.frontend_url}/profile"
            )
            
            message = MIMEMultipart("alternative")
            message["Subject"] = "🚀 Welcome to Pixel Canvas - Let's Create!"
            message["From"] = settings.from_email
            message["To"] = email
            
            html_part = MIMEText(html_content, "html")
            message.attach(html_part)
            
            await aiosmtplib.send(
                message,
                hostname=settings.smtp_server,
                port=settings.smtp_port,
                start_tls=True,
                username=settings.smtp_username,
                password=settings.smtp_password,
            )
            
            logger.info(f"Welcome email sent to {email}")
            return True
            
        except Exception as e:
            logger.error(f"Failed to send welcome email to {email}: {str(e)}")
            return False 