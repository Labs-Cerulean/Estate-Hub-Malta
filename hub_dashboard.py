import streamlit as st
import pandas as pd
import sqlite3

USERS = {'admin': 'Pra2026!', 'manager': 'Site2026!', 'viewer': 'View2026!'}

st.set_page_config(page_title="Estate Hub Malta", page_icon="logo.png", layout="wide")

# INLINE DATABASE CLASS - NO IMPORTS!
class Database:
    def __init__(self):
        self.conn = sqlite3.connect('estate_hub.db', check_same_thread=False)
        self.init_db()
    
    def init_db(self):
        self.conn.execute("""
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY, 
                name TEXT, 
                client TEXT, 
                city TEXT,
                pa_number TEXT DEFAULT '',
                bca_status TEXT DEFAULT 'AWAITING', 
                status TEXT DEFAULT 'Pending'
            )
        """)
        
        # Sample data from your Excel[file:142]
        if pd.read_sql("SELECT COUNT(*) FROM projects", self.conn).iloc[0,0] == 0:
            sample_projects = [
                ('P001', 'Dirjanu Supermarket', 'Agius', 'Ghajnsielem', '7246/22', 'DONE', 'Mobilised'),
                ('P002', 'Hotel All Season', 'Blue Clay', 'Victoria', '7298/24', 'AWAITING', 'In Process'),
                ('P003', 'Hotel Ghajnsielem', 'Blue Clay', 'Ghajnsielem', '753/25', 'AWAITING', 'Pending'),
                ('P004', 'Hotel Qala', 'Blue Clay', 'Qala', '3698/24', 'AWAITING', 'In Process'),
                ('P005', 'Cutajar Houses', 'Excel Investments', 'Xaghra', '4893/23', 'NO', 'Pending'),
                ('P006', 'Ex BOV Nadur', 'Excel Investments', 'Nadur', '575/24', 'AWAITING', 'In Process')
            ]
            self.conn.executemany("INSERT INTO projects VALUES(?,?,?,?,?,?,?)", sample_projects)
            self.conn.commit()
    
    def get_projects(self):
        return pd.read_sql("SELECT * FROM projects", self.conn)
    
    def update_project(self, project_id, pa_number, bca_status, status):
        self.conn.execute("UPDATE projects SET pa_number=?, bca_status=?, status=? WHERE id=?", 
                         (pa_number, bca_status, status, project_id))
        self.conn.commit()

db = Database()

if 'logged_in' not in st.session_state:
    st.session_state.logged_in = False
    st.session_state.username = None

def landing_page():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="logo.png" width="250">
        <h1 style='color: #1f77b4; font-size: 3rem;'>Estate Hub Malta</h1>
        <p style='color: #666;'>Malta's Premier Property Dashboard</p>
    </div>
    """, unsafe_allow_html=True)
    
    col1, col2 = st.columns(2)
    with col1: 
        username = st.text_input("👤 Username", placeholder="admin")
    with col2: 
        password = st.text_input("🔑 Password", type="password")
    
    if st.button("🚀 Enter Hub", type="primary", use_container_width=True):
        if username in USERS and password == USERS[username]:
            st.session_state.logged_in = True
            st.session_state.username = username
            st.success("✅ Welcome!")
            st.rerun()
        else:
            st.error("❌ Wrong credentials")
    
    st.caption("**admin/Pra2026!** | **manager/Site2026!** | **viewer/View2026!**")

def mobilization_module():
    st.header("🚧 Mobilization Tracker[file:142]")
    
    projects_df = db.get_projects()
    
    # Metrics
    col1, col2, col3 = st.columns(3)
    col1.metric("📊 Total Projects", len(projects_df))
    col2.metric("✅ Mobilised", len(projects_df[projects_df['status']=='Mobilised']))
    col3.metric("⏳ Pending", len(projects_df[projects_df['status']=='Pending']))
    
    # Progress chart
    progress_data = []
    for _, row in projects_df.iterrows():
        progress = 0
        if row['pa_number']: progress += 40
        if row['bca_status'] != 'AWAITING': progress += 30
        if row['status'] in ['Ready', 'Mobilised']: progress += 30
        progress_data.append({"Project": row['name'][:15], "Progress": progress})
    
    st.subheader("📈 Progress Overview")
    st.bar_chart(pd.DataFrame(progress_data).set_index("Project"), height=400)
    
    # Projects table
    st.subheader("📋 Project Details")
    st.dataframe(projects_df, use_container_width=True)
    
    # Update form
    st.subheader("🔄 Update Status")
    project_options = {row['id']: f"{row['name']} - {row['city']}" for _, row in projects_df.iterrows()}
    selected_id = st.selectbox("Select Project", list(project_options.keys()), 
                              format_func=lambda x: project_options[x])
    
    selected_project = projects_df[projects_df['id'] == selected_id].iloc[0]
    
    col1, col2, col3 = st.columns(3)
    with col1:
        new_pa = st.text_input("PA Number", selected_project['pa_number'], key=f"pa_{selected_id}")
    with col2:
        new_bca = st.selectbox("BCA Status", ['AWAITING', 'DONE', 'NO', 'PENDING'], 
                              index=['AWAITING', 'DONE', 'NO', 'PENDING'].index(selected_project['bca_status']),
                              key=f"bca_{selected_id}")
    with col3:
        new_status = st.selectbox("Status", ['Pending', 'In Process', 'Ready', 'Mobilised'], 
                                 index=['Pending', 'In Process', 'Ready', 'Mobilised'].index(selected_project['status']),
                                 key=f"status_{selected_id}")
    
    col1, col2 = st.columns(2)
    if col1.button("💾 Update Project", type="primary", use_container_width=True):
        db.update_project(selected_id, new_pa, new_bca, new_status)
        st.success(f"✅ {selected_project['name']} updated!")
        st.rerun()

def hub_dashboard():
    st.markdown("""
    <div style='text-align: center; padding: 2rem'>
        <img src="logo.png" width="200">
        <h1 style='color: #1f77b4;'>Estate Hub Malta</h1>
        <p>👋 Welcome back, **{}**!</p>
    </div>
    """.format(st.session_state.username.title()), unsafe_allow_html=True)
    
    tab1, tab2 = st.tabs(["🏠 Home", "🚧 Mobilization"])
    
    with tab1:
        st.success("✅ **Production Ready!**")
        st.markdown("""
        ### 📊 Hub Status
        - ✅ SQLite database created
        - ✅ 6 projects loaded[file:142]  
        - ✅ Real-time updates
        - ✅ Mobile responsive
        """)
        if st.button("🚪 Logout", use_container_width=True):
            st.session_state.logged_in = False
            st.session_state.username = None
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
