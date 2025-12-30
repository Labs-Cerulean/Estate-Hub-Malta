import streamlit as st
import pandas as pd
import hashlib

# Simple persistent storage (no SQLite for Streamlit Cloud)
if 'projects_data' not in st.session_state:
    st.session_state.projects_data = pd.DataFrame({
        'id': ['P001', 'P002', 'P003', 'P004', 'P005', 'P006'],
        'name': ['Dirjanu Supermarket', 'Hotel All Season', 'Cutajar Houses', 'Ex BOV Nadur', 
                'Hotel Ghajnsielem', 'Hotel Qala'],
        'client': ['Agius', 'Blue Clay', 'Excel Investments', 'Excel Investments', 
                  'Blue Clay', 'Blue Clay'],
        'city': ['Ghajnsielem', 'Victoria', 'Xaghra', 'Nadur', 'Ghajnsielem', 'Qala'],
        'pa_number': ['7246/22', '7298/24', '4893/23', '575/24', '753/25', '3698/24'],
        'bca_status': ['DONE', 'AWAITING', 'NO', 'AWAITING', 'AWAITING', 'AWAITING'],
        'status': ['Mobilised', 'In Process', 'Pending', 'In Process', 'Pending', 'Pending']
    })

if 'clients' not in st.session_state:
    st.session_state.clients = ['Agius', 'Blue Clay', 'Excel Investments', 'Next Developers']

USERS = {
    'admin': hashlib.sha256('Pra2026!'.encode()).hexdigest(),
    'manager': hashlib.sha256('Site2026!'.encode()).hexdigest(),
    'viewer': hashlib.sha256('View2026!'.encode()).hexdigest()
}

st.set_page_config(page_title="Estate Hub Malta", page_icon="./logo_icon.png", layout="wide")

def login_page():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="./logo.png" width="250">
        <h1 style='color: #1f77b4;'>Estate Hub Malta</h1>
        <p>Malta's Premier Property Dashboard</p>
    </div>
    """, unsafe_allow_html=True)
    
    col1, col2 = st.columns(2)
    with col1:
        username = st.text_input("👤 Username", placeholder="admin")
    with col2:
        password = st.text_input("🔑 Password", type="password")
    
    if st.button("🚀 Login", type="primary", use_container_width=True):
        password_hash = hashlib.sha256(password.encode()).hexdigest()
        if username in USERS and USERS[username] == password_hash:
            st.session_state.logged_in = True
            st.session_state.username = username
            st.rerun()
        else:
            st.error("❌ Invalid credentials")
    
    st.caption("**admin/Pra2026!** | **manager/Site2026!** | **viewer/View2026!**")

def add_client():
    new_client = st.text_input("➕ New Client Name")
    if st.button("Add Client", key="add_client"):
        if new_client and new_client not in st.session_state.clients:
            st.session_state.clients.append(new_client)
            st.success(f"✅ {new_client} added!")
            st.rerun()

def add_project():
    st.subheader("➕ New Project")
    col1, col2 = st.columns(2)
    with col1:
        project_name = st.text_input("Project Name")
        client = st.selectbox("Client", st.session_state.clients)
    with col2:
        city = st.text_input("City")
        pa_number = st.text_input("PA Number")
    
    if st.button("Create Project", 
