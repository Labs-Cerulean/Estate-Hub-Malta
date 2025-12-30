import streamlit as st
from mobilization import mobilization_module

USERS = {'admin': 'Pra2026!', 'manager': 'Site2026!', 'viewer': 'View2026!'}

st.set_page_config(page_title="Estate Hub Malta", page_icon="logo_icon.png", layout="wide")

if 'logged_in' not in st.session_state:
    st.session_state.logged_in = False
    st.session_state.username = None

def landing_page():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="logo.png" width="250">
        <h1 style='color: #1f77b4;'>Estate Hub Malta</h1>
    </div>
    """, unsafe_allow_html=True)
    
    # Login
    col1, col2 = st.columns(2)
    with col1: username = st.text_input("👤 Username")
    with col2: password = st.text_input("🔑 Password", type="password")
    
    if st.button("🚀 Enter Hub", type="primary"):
        if username in USERS and password == USERS[username]:
            st.session_state.logged_in = True
            st.session_state.username = username
            st.rerun()
        else:
            st.error("❌ Invalid credentials")

def hub_dashboard():
    st.markdown("<div style='text-align: center'><img src='logo.png' width='200'><h1>Estate Hub Malta</h1></div>", unsafe_allow_html=True)
    
    tab1, tab2 = st.tabs(["🏠 Home", "🚧 Mobilization"])
    
    with tab1:
        st.success("✅ Database + modules working!")
        if st.button("🚪 Logout"): 
            st.session_state.logged_in = False
            st.rerun()
    
    with tab2:
        mobilization_module()

def main():
    if st.session_state.logged_in:
        hub_dashboard()
    else:
        landing_page()

if __name__ == "__main__":
    main()
