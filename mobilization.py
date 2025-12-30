import streamlit as st
import pandas as pd
from datetime import datetime
from enum import Enum
from dataclasses import dataclass
from typing import Dict
try:
    from auth import auth_manager
except:
    st.error("auth.py missing!")
    st.stop()

st.set_page_config(page_title="PRA Tracker", layout="wide")

class Status(Enum):
    PENDING = "Pending"
    SUBMITTED = "Submitted"
    APPROVED = "Approved"
    COMPLETE = "Complete"

@dataclass
class Project:
    id: str
    name: str
    client: str
    pa_no: str = ""
    bca_no: str = ""
    status: Status = Status.PENDING
    mobilized: bool = False

class Manager:
    def __init__(self):
        self.projects = {
            "PRA001": Project("PRA001", "Msida School", "FTS"),
            "PRA002": Project("PRA002", "Gov Lot 1", "Housing"),
            "PRA003": Project("PRA003", "Gov Lot 2", "Housing"),
            "PRA004": Project("PRA004", "Tac Cawla", "Gozo Housing"),
            "PRA005": Project("PRA005", "Zebbug Council", "Zebbug Council")
        }
    
    def update(self, proj_id: str, **kwargs):
        if proj_id in self.projects:
            for k, v in kwargs.items():
                setattr(self.projects[proj_id], k, v)
            return True
        return False

@st.cache_resource
def get_mgr():
    return Manager()

def login_page():
    st.title("🔐 PRA Mobilization Tracker")
    col1, col2 = st.columns(2)
    with col1:
        username = st.text_input("Username")
    with col2:
        password = st.text_input("Password", type="password")
    
    if st.button("Login"):
        if auth_manager.login(username, password):
            st.session_state.authenticated = True
            st.session_state.username = username
            st.success("Logged in!")
            st.rerun()
        else:
            st.error("Wrong credentials")

def dashboard():
    st.title("🏗️ Mobilization Dashboard")
    
    mgr = get_mgr()
    user = auth_manager.get_current_user()
    
    col1, col2, col3 = st.columns(3)
    with col2:
        st.info(f"👤 {user.name} | {user.role}")
    
    # Progress chart
    data = []
    for p in mgr.projects.values():
        progress = 0
        if p.pa_no: progress += 33
        if p.bca_no: progress += 33
        if p.mobilized: progress += 34
        data.append({"Project": p.name[:15], "Progress": progress})
    
    df = pd.DataFrame(data)
    st.bar_chart(df.set_index("Project"))
    
    # Project cards
    for proj_id, proj in mgr.projects.items():
        with st.container():
            col1, col2, col3 = st.columns(3)
            with col1:
                st.metric("PA", proj.pa_no or "Pending")
            with col2:
                st.metric("BCA", proj.bca_no or "Pending")
            with col3:
                st.metric("Status", proj.status.value)
            
            pa_no = st.text_input("PA #", proj.pa_no, key=f"pa_{proj_id}")
            bca_no = st.text_input("BCA #", proj.bca_no, key=f"bca_{proj_id}")
            
            col1, col2, col3 = st.columns(3)
            if col1.button("Submit PA", key=f"pa_{proj_id}"):
                mgr.update(proj_id, pa_no=pa_no, status=Status.SUBMITTED)
                st.rerun()
            if col2.button("Submit BCA", key=f"bca_{proj_id}"):
                mgr.update(proj_id, bca_no=bca_no, status=Status.APPROVED)
                st.rerun()
            if col3.button("Mobilized", key=f"mob_{proj_id}"):
                mgr.update(proj_id, mobilized=True, status=Status.COMPLETE)
                st.rerun()

def main():
    if not st.session_state.get('authenticated', False):
        login_page()
    else:
        if st.button("Logout"):
            auth_manager.logout()
            st.rerun()
        dashboard()

if __name__ == "__main__":
    main()
