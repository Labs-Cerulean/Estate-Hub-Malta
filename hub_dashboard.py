import streamlit as st

# Simple users for PRA team
USERS = {
    'admin': 'Pra2026!',
    'manager': 'Site2026!',
    'viewer': 'View2026!'
}

st.set_page_config(
    page_title="Estate Hub", 
    page_icon="https://raw.githubusercontent.com/nicholasvpra/Estate-Hub-Malta/main/logo.png",
    layout="wide"
)

if 'logged_in' not in st.session_state:
    st.session_state.logged_in = False
    st.session_state.username = None

def landing_page():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="./logo.png" width="200">
        <h2 style='color: #666; font-size: 2rem;'>Project Central</h2>
    </div>
    """, unsafe_allow_html=True)
    
    st.markdown("---")
    
    col1, col2, col3 = st.columns(3)
    
    with col1:
        st.markdown("""
        ### 📊 Mobilization Tracker
        **PA Approvals • BCA Notifications • Site Status**
        - Live project progress
        - Real-time approvals
        - Mobile site updates
        """)
        st.button("🚀 Launch", disabled=True)
    
    with col2:
        st.markdown("""
        ### 💰 Payment Hub  
        **Contractor Payments • Cashflow • Forecasts**
        - Weekly payment schedules
        - Outstanding balances
        - Supplier invoices
        """)
        st.button("💳 Launch", disabled=True)
    
    with col3:
        st.markdown("""
        ### 📋 Supplier Portal
        **Orders • Deliveries • Compliance**
        - Supplier database
        - Order tracking
        - VAT compliance
        """)
        st.button("📦 Launch", disabled=True)
    
    st.markdown("---")
    st.markdown("<div style='text-align: center; padding: 1rem;'>", unsafe_allow_html=True)
    st.markdown("<h3>🔐 Secure Login Required</h3>", unsafe_allow_html=True)
    st.markdown("</div>", unsafe_allow_html=True)
    
    col1, col2 = st.columns([1, 2])
    with col1:
        st.markdown("### 👤 Team Login")
    with col2:
        username = st.text_input("Username", placeholder="admin, manager, viewer")
        password = st.text_input("Password", type="password")
    
    if st.button("🚀 Enter PRA Hub", type="primary", use_container_width=True):
        if username in USERS and password == USERS[username]:
            st.session_state.logged_in = True
            st.session_state.username = username
            st.balloons()
            st.success(f"✅ Welcome to PRA Hub, {username.title()}!")
            st.rerun()
        else:
            st.error("❌ Invalid credentials")
    
    st.markdown("---")
    st.caption("**🔑 Test Logins:** admin/Pra2026! | manager/Site2026! | viewer/View2026!")
    st.caption("*PRA Construction Ltd - Il-Mosta, Malta*")

def hub_dashboard():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <h1 style='color: #1f77b4;'>🏗️ PRA Construction Hub</h1>
        <p>Welcome back, **{}**! 👋</p>
    </div>
    """.format(st.session_state.username.title()), unsafe_allow_html=True)
    
    col1, col2, col3, col4 = st.columns(4)
    with col1:
        st.markdown("### 📊 Status")
        st.success("All systems **online** ✅")
    with col2:
        st.markdown("### 🏗️ Active Sites")
        st.info("**5** sites operational")
    with col3:
        st.markdown("### 💰 Cashflow")
        st.success("**€2.4M** forecast")
    with col4:
        if st.button("🚪 Logout", use_container_width=True):
            st.session_state.logged_in = False
            st.session_state.username = None
            st.rerun()
    
    st.markdown("---")
    
    col1, col2, col3 = st.columns(3)
    
    with col1:
        st.markdown("### 🚀 Quick Launch")
        if st.button("📊 Mobilization Tracker", use_container_width=True):
            st.info("🎉 Coming soon!")
        if st.button("💰 Payment Hub", use_container_width=True):
            st.info("🎉 Coming soon!")
        if st.button("📋 Supplier Portal", use_container_width=True):
            st.info("🎉 Coming soon!")
    
    with col2:
        st.markdown("### 📈 Today's Stats")
        st.metric("Projects Active", "7")
        st.metric("PA Pending", "2") 
        st.metric("Sites Mobilized", "3")
    
    with col3:
        st.markdown("""
        ### ✅ Hub Ready!
        **Modules to build:**
        • Mobilization Tracker[file:140]
        • Payment Schedules
        • Supplier Management
        • Project Reports
        """)
    
    st.markdown("---")
    st.caption("**PRA Construction Hub v1.0** - Il-Mosta, Malta | Built for scalability")

def main():
    if st.session_state.logged_in:
        hub_dashboard()
    else:
        landing_page()

if __name__ == "__main__":
    main()
